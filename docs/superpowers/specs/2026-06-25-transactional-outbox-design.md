# Transactional Outbox (publikacja eventów) — Design / Spec

**Data:** 2026-06-25
**Status:** zatwierdzony do planowania
**Cel:** Zagwarantować, że integration event jest opublikowany na Kafkę **wtedy i tylko wtedy**, gdy zmiana stanu domeny została zatwierdzona — przez wzorzec transactional outbox po stronie producenta.

## 1. Problem i cel

### Problem
Obecnie handlery robią `repo.save()` (commit do DB), a następnie `eventBus.publish()` (wysyłka na Kafkę) — dwie osobne operacje. Jeśli commit przejdzie, a publish padnie (broker niedostępny, crash procesu), **event jest zgubiony** mimo zmiany stanu. To świadomie udokumentowana luka at-least-once (spec MVP §3, „bez pełnego outboxa").

### Cel
Atomowo zapisać zmianę domeny **i** „zamiar publikacji eventu" w jednej transakcji DB; osobny proces (relay) niezawodnie dowozi event do Kafki. Dzięki temu: brak zgubionych eventów; relay działa at-least-once, a konsumenci są już idempotentni.

### Zakres
- **W zakresie:** strona producenta wszystkich 3 integration eventów: `App\Enrollment\Domain\Event\UserEnrolled`, `App\Progress\Domain\Event\LessonCompleted`, `App\Progress\Domain\Event\CourseCompleted`.
- **Poza zakresem:** luka `send→save` w module Notification (strona konsumenta + zewnętrzne I/O — maila nie da się idempotentnie cofnąć; to inny problem). Wielo-instancyjny relay (`SKIP LOCKED`). Licznik prób / poison-queue dla relay.

## 2. Architektura

Producer-side transactional outbox:

1. `command.bus` owija każdy handler w transakcję DB (middleware `doctrine_transaction`).
2. Handler zapisuje stan domeny (`repo.save()`) i publikuje event przez `EventBusInterface`, którego nową implementacją jest `OutboxEventBus` — zamiast słać na Kafkę, **persistuje wiersz w tabeli `outbox`** (ten sam `EntityManager`, ta sama transakcja).
3. Na końcu handlera middleware robi `flush` + `commit` → zmiana domeny i wiersz outbox commitują się **atomowo**. Wyjątek → `rollback` (żadne z nich).
4. Osobny worker `app:outbox:relay` czyta niewysłane wiersze, deserializuje event, dispatchuje na **surowy `event.bus`** (routing → transport Kafka), oznacza wiersz jako wysłany.
5. Konsumenci Kafki (Progress, Notification) konsumują bez zmian — są idempotentni, więc ewentualne ponowne dostarczenie przez relay jest bezpieczne.

Mechanizm jest przekrojowy → komponenty w module **Shared**.

## 3. Komponenty

### `App\Shared\Infrastructure\Outbox\OutboxMessage` (encja Doctrine, tabela `outbox`)
- `id: Uuid` (PK, app-assigned)
- `messageType: string` — FQCN klasy eventu (np. `App\Enrollment\Domain\Event\UserEnrolled`)
- `payload: string` — event zserializowany do JSON
- `createdAt: \DateTimeImmutable`
- `sentAt: ?\DateTimeImmutable` (null = niewysłany)
- Indeks na `sentAt` (do pobierania niewysłanych).
- Metody: `static create(string $messageType, string $payload): self`; `markSent(\DateTimeImmutable $at): void`; gettery `getId/getMessageType/getPayload`.

### `App\Shared\Infrastructure\Bus\OutboxEventBus implements App\Shared\Application\Bus\EventBusInterface`
- `publish(object $event): void` → `messageType = $event::class`; `payload = $serializer->serialize($event, 'json')`; `$em->persist(OutboxMessage::create(...))` (BEZ `flush` — commit robi middleware transakcyjne).
- Serializacja: Symfony Serializer (JSON) — czytelny payload, łatwa rekonstrukcja w relay.
- **Zastępuje** `MessengerEventBus` jako wiązanie `EventBusInterface`. `MessengerEventBus` pozostaje w kodzie, ale nie jest już aliasem `EventBusInterface` (może zostać usunięty, jeśli nieużywany — patrz §8).

### `App\Shared\Infrastructure\Outbox\OutboxRepository` (port + impl Doctrine)
- `interface OutboxRepository { /** @return list<OutboxMessage> */ public function unsent(int $limit): array; public function save(OutboxMessage $m): void; }`
- `DoctrineOutboxRepository`: `unsent` → `findBy(['sentAt' => null], ['createdAt' => 'ASC'], $limit)`; `save` → persist+flush.

### `App\Shared\Infrastructure\Outbox\OutboxRelay` (serwis)
- Zależności: `OutboxRepository`, `MessageBusInterface $eventBus` (wstrzyknięty **surowy `event.bus`**, NIE `EventBusInterface`), `SerializerInterface $serializer`, `ClockInterface`/lub `\DateTimeImmutable` (czas).
- `relayBatch(int $limit): int` — pobiera `unsent($limit)`; dla każdego wiersza:
  1. `$event = $serializer->deserialize($m->getPayload(), $m->getMessageType(), 'json')`
  2. `$eventBus->dispatch($event)` → Messenger routuje po klasie na transport Kafka (wysyłka)
  3. `$m->markSent($now)`; `repo.save($m)`
  - per-wiersz `try/catch`: błąd → log + kontynuuj (wiersz zostaje niewysłany do inspekcji).
  - Zwraca liczbę przetworzonych (wysłanych) wierszy.

> Brak pętli: relay używa `MessageBusInterface` (`@event.bus`), a handlery `EventBusInterface` (`OutboxEventBus`). Dispatch eventu (routowanego do transportu) wysyła go na transport, NIE uruchamia handlerów synchronicznie; handlery konsumentów są związane `fromTransport`, więc odpalą się dopiero przy konsumpcji z Kafki.

### `App\Shared\UI\Command\OutboxRelayCommand` (`app:outbox:relay`)
- Worker w pętli (jak `messenger:consume`): `relayBatch($limit)`; jeśli 0 — `sleep` krótki; powtarzaj.
- Opcje: `--limit=100` (rozmiar partii), `--once` (jeden przebieg — do testów/cron).

## 4. Konfiguracja

- `config/packages/messenger.yaml` — `command.bus.middleware`: dodać `doctrine_transaction` (po `validation`).
- `config/services.yaml`:
  - alias `EventBusInterface` → `OutboxEventBus` (zamiast `MessengerEventBus`).
  - `OutboxRelay` z argumentem `$eventBus: '@event.bus'` (surowy bus).
- `config/packages/doctrine.yaml` — mapowanie `Outbox` (dir `src/Shared/Infrastructure/Outbox`, prefix `App\Shared\Infrastructure\Outbox`).
- Migracja: tabela `outbox`.

## 5. Przepływ danych

```
Command → command.bus [doctrine_transaction: BEGIN]
  Handler: repo.save() (INSERT domena)        ── w transakcji
           eventBus.publish() = OutboxEventBus.persist(OutboxMessage) ── w transakcji
[doctrine_transaction: flush + COMMIT]  → domena + outbox atomowo (lub ROLLBACK)

app:outbox:relay (osobny proces):
  unsent() → deserialize → event.bus.dispatch → [routing] → transport Kafka (produce)
           → markSent → save
Konsumenci Kafki (progress_*/notification_*) → jak dotąd (idempotentni)
```

## 6. Obsługa błędów i odporność

- **Atomowość**: transakcja gwarantuje domena+outbox razem albo nic.
- **Relay at-least-once**: `sentAt` ustawiany dopiero PO udanym `dispatch` (produce). Crash po produce, przed markSent → wiersz ponownie zrelay'owany → konsumenci dedupują (idempotencja: Progress skip-if-exists, Notification dedupe (typ,user,course)).
- **Błąd produce/deserializacji per wiersz**: log + kontynuacja; wiersz zostaje niewysłany (widoczny w tabeli / `messenger:failed:show` go nie obejmuje — to inny mechanizm). Brak licznika prób w MVP.
- **Jedna instancja relay** (jak nasze konsumery). `SELECT ... FOR UPDATE SKIP LOCKED` pod wiele instancji — przyszłość.

## 7. Testy

- **Unit**: `OutboxEventBus.publish` persistuje `OutboxMessage` z poprawnym `messageType` + JSON payload (fake EM). `OutboxRelay.relayBatch` z niewysłanymi (fake repo): deserializuje, dispatchuje na fake bus, oznacza `sentAt`.
- **Integration**:
  - *Atomowość*: dispatch `EnrollStudentCommand` → (a) enrollment zapisany, (b) istnieje wiersz outbox z `UserEnrolled`, (c) transport `kafka_events` (in-memory) **pusty** (event jeszcze nie poszedł na Kafkę).
  - *Rollback*: komenda rzucająca w handlerze (np. zapis na nieopublikowany kurs) → brak wiersza outbox i brak zmiany domeny.
  - *Relay*: niewysłany wiersz → `relayBatch` → event na transporcie in-memory (`getSent`) + `sentAt` ustawiony.
- **Aktualizacja istniejących testów (ripple)**: testy asercjonujące `getSent()` zaraz po komendzie zmieniają asercję na wiersz outbox (event idzie teraz do outboxa, nie wprost na Kafkę):
  - `tests/Integration/Enrollment/EnrollStudentIntegrationTest.php`
  - `tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php`
  - `tests/Functional/Enrollment/EnrollControllerTest.php`
  - (sprawdzić inne asercjonujące `messenger.transport.kafka_*` `getSent` po komendzie)
- Testy nadal pristine + izolowane (DAMA).

## 8. Świadome decyzje i uwagi

- Outbox tylko producent (atomowość publikacji). Konsument-side `send→save` poza zakresem.
- `OutboxEventBus` zastępuje `MessengerEventBus` jako `EventBusInterface`. Relay używa surowego `event.bus` (`MessageBusInterface`) — brak pętli. Jeśli `MessengerEventBus` nie jest już nigdzie używany, można go usunąć (decyzja na etapie planu po sprawdzeniu referencji).
- Payload JSON przez Symfony Serializer — eventy mają pola string (Uuid jako string), więc serializacja/deserializacja jest trywialna.
- **Caveat DAMA + `doctrine_transaction`**: DAMA owija test w transakcję; middleware otwiera kolejną → zagnieżdżenie przez savepointy (Doctrine domyślnie `use_savepoints: true`). Zweryfikować w testach; w razie problemów jawnie ustawić `doctrine.dbal.connections.default.use_savepoints: true`.
- Relay uruchamiany jak konsumenci (worker) — udokumentować w README obok `messenger:consume`.

## 9. Definicja ukończenia

- [ ] `doctrine_transaction` na `command.bus`; handlery commitują domenę + outbox atomowo.
- [ ] `OutboxMessage` + migracja `outbox`; `OutboxEventBus` jest implementacją `EventBusInterface`.
- [ ] `OutboxRelay` + komenda `app:outbox:relay` dowożą eventy na Kafkę i oznaczają `sentAt`.
- [ ] Test atomowości (outbox zapisany, Kafka pusta przed relay), rollback, relay.
- [ ] Zaktualizowane istniejące testy (ripple) — całość zielona, pristine.
- [ ] README: jak uruchomić relay.
- [ ] Wszystkie 3 integration eventy przechodzą przez outbox.
