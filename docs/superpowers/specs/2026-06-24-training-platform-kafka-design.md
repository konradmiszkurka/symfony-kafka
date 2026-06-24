# Platforma szkoleniowa (Symfony + Kafka) — Design / Spec

**Data:** 2026-06-24
**Status:** zatwierdzony do planowania
**Cel:** MVP realnego produktu — platforma szkoleniowa z event-driven architekturą opartą o Kafkę, budowana wg dobrych praktyk (modularny monolit, hexagonal + lekkie DDD).

## 1. Cel i zakres

### Cel biznesowy
Platforma, na której **instruktorzy** tworzą kursy z lekcjami, a **studenci** zapisują się na nie i śledzą swój postęp. MVP ma być realnym zalążkiem produktu z możliwością rozbudowy, nie tylko demem.

### Zakres MVP (in scope)
- Rejestracja, logowanie, role **STUDENT** i **INSTRUCTOR**
- Katalog kursów: kurs → moduły → lekcje (CRUD instruktora, przeglądanie przez studenta)
- Zapis studenta na kurs (enrollment)
- Śledzenie postępów: oznaczanie lekcji jako ukończone, % ukończenia kursu
- Powiadomienia asynchroniczne (mail) wyzwalane eventami
- API REST (JSON) + prosty frontend w Twig (server-side rendering)

### Poza zakresem MVP (backlog)
- Płatności / zamówienia
- Certyfikaty (generowanie PDF) — `CourseCompleted` przygotowuje grunt pod to
- Quizy / egzaminy
- Oceny i recenzje kursów
- SPA / osobny frontend
- Wgrywanie wideo / hosting materiałów (lekcja = treść tekstowa + ewentualny link)

## 2. Architektura

### Styl
**Modularny monolit**, jedna aplikacja Symfony. Moduły = bounded contexts. Każdy moduł ma warstwy:

- **Domain** — encje, value objects, domain eventy, porty (interfejsy). Zero zależności od frameworka/infry.
- **Application** — use-case'y: command/query handlery (Symfony Messenger, bus synchroniczny). Orkiestracja domeny.
- **Infrastructure** — adaptery portów: repozytoria Doctrine, producent/konsument Kafki, Mailer.
- **UI** — kontrolery (API + Twig), formularze, szablony.

**Reguła zależności (hexagonal):** Domain ← Application ← Infrastructure/UI. Domain nie zna Doctrine, Symfony ani Kafki.

### Moduły (bounded contexts)

| Moduł | Odpowiedzialność | Publikuje eventy | Konsumuje eventy |
|-------|------------------|------------------|------------------|
| **Identity** | rejestracja, login, role | — | — |
| **Catalog** | kursy, moduły, lekcje | — | — |
| **Enrollment** | zapis studenta na kurs | `UserEnrolled` | — |
| **Progress** | ukończone lekcje, % ukończenia | `LessonCompleted`, `CourseCompleted` | `UserEnrolled` |
| **Notification** | wysyłka maili na podstawie eventów | — | `UserEnrolled`, `CourseCompleted` |

### Granice modułów
Moduły **nie współdzielą encji**. Komunikacja odbywa się przez identyfikatory i porty:

- Enrollment przechowuje `userId` + `courseId`, nie encję `Course` ani `User`.
- Gdy Progress potrzebuje struktury kursu (lista lekcji), pobiera ją przez port `CourseStructureProvider` zaimplementowany w infrastrukturze Catalog (wywołanie in-process, w obrębie monolitu — dozwolone).
- Walidacja istnienia kursu/usera przy zapisie odbywa się przez porty zapytań (np. `CourseExistenceChecker`), nie przez bezpośredni dostęp do cudzych repozytoriów.

## 3. Komunikacja i przepływy

### Dwa poziomy komunikacji
1. **Wewnątrz modułu** — Symfony Messenger, bus synchroniczny (`command.bus`, `query.bus`).
2. **Między modułami** — **integration eventy** publikowane na Kafkę przez Messenger z transportem Kafka (pod spodem `php-rdkafka`).

### Rozróżnienie eventów
- **Domain event** — wewnętrzny, w obrębie agregatu/modułu (np. `EnrollmentCreated` rejestrowany na agregacie).
- **Integration event** — kontrakt między modułami, publikowany na Kafkę (np. `UserEnrolled`). Wersjonowany, serializowany do JSON, niesie tylko dane potrzebne konsumentom (identyfikatory + minimum payloadu).

### Topiki Kafki
- `enrollment.events` — `UserEnrolled`
- `progress.events` — `LessonCompleted`, `CourseCompleted`

Każdy konsument działa we własnej **consumer group** (np. `progress-consumer`, `notification-consumer`), dzięki czemu wiele modułów niezależnie czyta ten sam topic.

### Przepływ A — zapis na kurs
1. Student wysyła żądanie (Twig form / `POST /api/courses/{id}/enroll`).
2. Kontroler dispatchuje `EnrollUserCommand` na sync bus.
3. Handler: waliduje istnienie kursu (port), tworzy agregat `Enrollment`, zapisuje przez Doctrine, rejestruje domain event.
4. Po commit transakcji publikowany integration event `UserEnrolled` na `enrollment.events`.
5. **Konsument Progress** (`progress-consumer`): tworzy `CourseProgress` dla (userId, courseId), pobierając listę lekcji przez `CourseStructureProvider`. Idempotentnie — jeśli już istnieje, pomija.
6. **Konsument Notification** (`notification-consumer`): wysyła mail „witaj na kursie". Idempotentnie — dedupe po ID eventu.

### Przepływ B — ukończenie lekcji
1. Student oznacza lekcję ukończoną (`POST /api/courses/{id}/lessons/{lessonId}/complete`).
2. `MarkLessonCompletedCommand` → handler aktualizuje `CourseProgress`, rejestruje `LessonCompleted`.
3. Jeśli ukończono wszystkie lekcje → agregat rejestruje też `CourseCompleted`.
4. Eventy publikowane na `progress.events`.
5. **Konsument Notification**: na `CourseCompleted` wysyła mail z gratulacjami (grunt pod przyszły certyfikat).

### Publikacja eventów — spójność
Publikacja na Kafkę następuje **po** commicie transakcji bazodanowej (żeby nie wysłać eventu dla niezapisanego stanu). W MVP realizujemy to przez dispatch na bus z transportem Kafka wykonywany po flushu Doctrine (Messenger middleware / `kernel.terminate` lub dispatch po `flush()` w handlerze). Pełny transactional outbox jest poza zakresem MVP, ale granice modułów go nie wykluczają w przyszłości.

## 4. Model domenowy (zarys)

### Identity
- `User` (id, email, hasłowy hash, role[]). Implementuje `UserInterface` Symfony Security.

### Catalog
- `Course` (id, title, description, instructorId, status: DRAFT/PUBLISHED)
- `Module` (id, courseId, title, position) — sekcja kursu
- `Lesson` (id, moduleId, title, content, position)
- Tylko PUBLISHED kursy są widoczne dla studentów i dostępne do zapisu.

### Enrollment
- `Enrollment` (id, userId, courseId, enrolledAt). Unikalność (userId, courseId) — nie można zapisać się dwa razy.

### Progress
- `CourseProgress` (id, userId, courseId, completedLessonIds[], totalLessons, completedAt?). Metoda liczenia % i wykrycia ukończenia kursu.

### Notification
- `SentNotification` (id, type, recipientUserId, eventId, sentAt) — także dla idempotencji/dedupe.

## 5. Obsługa błędów i odporność

- **Retry + backoff** dla konsumentów (Messenger retry strategy: np. 3 próby, rosnący delay).
- **Dead-letter**: nieudane wiadomości lądują na osobnym `failed` transporcie do inspekcji/ponowienia.
- **Idempotentni konsumenci** — Kafka gwarantuje at-least-once, więc:
  - Progress: tworzenie/aktualizacja idempotentna (sprawdzenie istnienia, brak duplikatów lekcji).
  - Notification: dedupe po `eventId` (tabela `SentNotification`).
- **Walidacja domenowa** → wyjątki domenowe mapowane na odpowiedzi HTTP 4xx (np. zapis na nieistniejący kurs → 404, podwójny zapis → 409).
- **Bezpieczeństwo**: dostęp do akcji instruktora chroniony rolą INSTRUCTOR; student widzi tylko swoje postępy.

## 6. Strategia testów (piramida)

- **Unit** — czysta domena (encje, value objects, logika liczenia postępu, reguły zapisu). Bez infry, najwięcej testów.
- **Integration** — command/query handlery z transportem Messenger `in-memory` (sync, bez prawdziwej Kafki → szybkie i deterministyczne). Repozytoria testowane przeciw realnej bazie (Postgres testowy) lub SQLite, do ustalenia w planie.
- **Functional** — kontrolery (API + Twig) przez `WebTestCase`: rejestracja, login, zapis, ukończenie lekcji.
- **Smoke (opcjonalny)** — jeden test end-to-end przeciw realnej Kafce z Docker Compose, weryfikujący publish→consume. Reszta testów nie dotyka realnej Kafki.

## 7. Stack i infrastruktura

### Technologie
- **PHP 8.4**, **Symfony 7.x**
- **Doctrine ORM** (migracje przez doctrine-migrations)
- **Symfony Messenger** + transport Kafka (pod spodem rozszerzenie `php-rdkafka`)
- **Twig** (frontend SSR), **Symfony Security** (form login + role)
- **Symfony Mailer** (powiadomienia)

> Uwaga implementacyjna: konkretny pakiet transportu Kafka dla Messengera zostanie wybrany na etapie planu (oficjalny bridge, jeśli dostępny w danej wersji Symfony, lub utrzymywany pakiet społecznościowy). Wymóg twardy: produkcja/konsumpcja przez Kafkę z widocznymi consumer groups; testy na transporcie in-memory.

### Docker Compose (środowisko deweloperskie)
- `php` (php-fpm 8.4 z rozszerzeniem rdkafka) + `nginx`
- `postgres` — baza danych
- `kafka` — **tryb KRaft** (bez Zookeepera)
- `kafka-ui` — podgląd topiców, partycji, consumer groups, offsetów
- `mailpit` — podgląd wysłanych maili w dev

## 8. Struktura katalogów (proponowana)

```
src/
  Identity/
    Domain/  Application/  Infrastructure/  UI/
  Catalog/
    Domain/  Application/  Infrastructure/  UI/
  Enrollment/
    Domain/  Application/  Infrastructure/  UI/
  Progress/
    Domain/  Application/  Infrastructure/  UI/
  Notification/
    Domain/  Application/  Infrastructure/  UI/
  Shared/            # współdzielone kontrakty: bazowy integration event, busy, ID-ki
config/
docker/
tests/
  Unit/  Integration/  Functional/
```

## 9. Definicja ukończenia MVP

- [ ] Użytkownik może się zarejestrować, zalogować, ma rolę STUDENT lub INSTRUCTOR
- [ ] Instruktor tworzy kurs z modułami i lekcjami; może go opublikować
- [ ] Student przegląda katalog opublikowanych kursów i zapisuje się
- [ ] Zapis publikuje `UserEnrolled` na Kafkę; Progress inicjuje postęp; Notification wysyła mail (widoczny w Mailpit)
- [ ] Student oznacza lekcje jako ukończone; system liczy % i wykrywa ukończenie kursu
- [ ] `CourseCompleted` → mail z gratulacjami
- [ ] Konsumenci idempotentni; nieudane wiadomości trafiają na dead-letter
- [ ] Piramida testów (unit/integration/functional) przechodzi; CI uruchamia testy
- [ ] `docker compose up` stawia całe środowisko (Symfony + Postgres + Kafka + Kafka UI + Mailpit)
