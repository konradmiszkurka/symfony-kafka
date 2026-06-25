# Platforma szkoleniowa (Symfony + Kafka)

Modularny monolit (hexagonal + lekkie DDD), event-driven przez Kafkę.
Spec: `docs/superpowers/specs/2026-06-24-training-platform-kafka-design.md`.

## Wymagania
- Docker + Docker Compose (PHP/Composer NIE są potrzebne na hoście — działają w kontenerze).

## Uruchomienie
```bash
docker compose build php
docker compose up -d
docker compose run --rm php bin/console doctrine:database:create --if-not-exists
```

## Adresy
| Usługa | URL |
|--------|-----|
| Aplikacja | http://localhost:8080 |
| Health | http://localhost:8080/health |
| Kafka UI | http://localhost:8081 |
| Mailpit | http://localhost:8025 |

## Komendy (w kontenerze)
```bash
docker compose exec php bin/console <cmd>     # konsola Symfony
docker compose exec php vendor/bin/phpunit    # testy
docker compose exec php composer <cmd>        # composer
```

## Struktura
`src/<Moduł>/{Domain,Application,Infrastructure,UI}` — moduły: Identity, Catalog, Enrollment, Progress, Notification, Shared.

## Konsumenci Kafki (event-driven)
Eventy są publikowane na Kafkę; konsumenci to procesy Messengera (osobne consumer groups):
```bash
docker compose run --rm php bin/console messenger:setup-transports failed   # raz, dead-letter
docker compose exec php bin/console messenger:consume progress_enrollment_in -vv      # inicjalizacja postępu
docker compose exec php bin/console messenger:consume notification_enrollment_in -vv  # mail powitalny
docker compose exec php bin/console messenger:consume notification_progress_in -vv    # mail gratulacyjny
```
Maile widać w Mailpit (http://localhost:8025), eventy w Kafka UI (http://localhost:8081).
Nieudane wiadomości: `bin/console messenger:failed:show`.

### Relay outboxa (producent → Kafka)
Eventy domenowe trafiają najpierw do tabeli `outbox` (atomowo ze zmianą stanu). Relay dowozi je na Kafkę:
```bash
docker compose exec php bin/console app:outbox:relay -vv              # worker w pętli (domyślny sleep 1 s)
docker compose exec php bin/console app:outbox:relay --once           # jeden przebieg (cron)
docker compose exec php bin/console app:outbox:relay --sleep=5        # worker z 5-sekundowym interwałem
```

**Graceful shutdown**: Relay obsługuje sygnały `SIGTERM` i `SIGINT` przez `SignalableCommandInterface`.
Po odebraniu sygnału kończy bieżącą iterację batcha, wypisuje `Zatrzymano relay.` i wychodzi z kodem 0.
Zakłada, że w danym momencie działa tylko jedna instancja relay (brak blokady wyścigu).

Opcje:
- `--limit=N` — rozmiar batcha (domyślnie 100)
- `--once` — jeden przebieg i wyjście
- `--sleep=N` — interwał w sekundach gdy batch jest pusty (domyślnie 1)

### Monitoring outboxa
```bash
docker compose exec php bin/console app:outbox:status                 # sprawdź stan (domyślny próg 5 min)
docker compose exec php bin/console app:outbox:status --stuck-after=2 # próg zalegania 2 minuty
```

Komenda wypisuje liczbę niewysłanych wierszy i liczbę zalegających (bez `sentAt` i `createdAt` starsze niż próg).
**Zwraca kod 1 (FAILURE) gdy są zalegające wiersze** — dzięki temu nadaje się do monitoringu / cron alertów:
```bash
# w cronie lub healthchecku:
bin/console app:outbox:status --stuck-after=5 || alert "Outbox ma zalegające wiersze!"
```
