# Stan prac — wznowienie (zapis: 2026-06-24)

## Gdzie jesteśmy
Platforma szkoleniowa Symfony 7.4 + Kafka (modularny monolit, hexagonal + lekkie DDD).
Tryb wykonania: **Subagent-Driven Development** (fresh subagent per task + review + merge per faza).
**Reguła twarda: commity BEZ śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code").

## ✅ MVP KOMPLETNE — wszystkie 5 faz na `main`. 45 testów zielonych. **Wypchnięte na GitHub** (origin: konradmiszkurka/symfony-kafka).

## Status faz
- **Faza 1 (Fundament)** — ✅ na `main` (Docker, MySQL 8.4, Kafka KRaft, skeleton, busy, /health)
- **Faza 2 (Identity)** — ✅ na `main` (rejestracja, login, role STUDENT/INSTRUCTOR, /dashboard)
- **Faza 3 (Catalog)** — ✅ na `main` (agregat Course→Section→Lesson, CRUD instruktora, przeglądanie)
- **Faza 4 (Enrollment + producent Kafki)** — ✅ na `main` (własny transport Kafka, zapis na kurs → UserEnrolled)
- **Faza 5 (Progress + Notification konsumenci)** — ✅ na `main` (konsumenci Kafki, postępy, maile)

## DOKŁADNY następny krok (po restarcie)
1. `main` jest na `d3566d6` (Fazy 1-4 + wszystkie plany/specy).
2. Faza 5 ukończona na gałęzi **`faza-5-progress-notification`** (HEAD `08b13e2`, 6 commitów, 6/6 tasków zrobionych i zreviewowanych, pełny zestaw **45 testów zielonych pristine**).
3. Finalne whole-branch review Fazy 5 było uruchomione (opus) tuż przed zapisem — **trzeba je powtórzyć** (agent w tle przepadł przy restarcie). Pakiet: `.superpowers/sdd/review-d3566d6..08b13e2.diff`.
4. Jeśli review = Ready to merge → **merge do main**:
   ```bash
   docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit   # spodziewane: 45 green
   git checkout main && git merge faza-5-progress-notification
   docker compose run --rm php vendor/bin/phpunit                                  # na zmergowanym main
   git branch -d faza-5-progress-notification
   ```
5. Po merge: **MVP KOMPLETNE** (wszystkie pozycje Definicji Ukończenia ze specu §9).

## Szczegółowy ledger postępu
`.superpowers/sdd/progress.md` (lokalny, gitignored — przetrwa restart na dysku) ma per-task log wszystkich faz.

## Znane non-blocking follow-upy (do rozważenia po MVP)
- Notification: kolejność `send→save` to świadomy at-least-once trade-off (udokumentowane); pełna gwarancja braku duplikatów wymaga transactional outbox.
- `KafkaTransport`: brak testów jednostkowych send/get/ack bez brokera (round-trip pokryty smoke-testem grupy `kafka`).
- CSRF na surowym formularzu „Opublikuj" (Faza 3, chroniony rolą — Minor).
- UI: brak wizualnego rozróżnienia ukończonej lekcji; hardcodowane maile w testach (DAMA-izolowane).

## Uruchomienie środowiska
`docker compose up -d` → app :8080, Kafka UI :8081, Mailpit :8025. Komendy w kontenerze: `docker compose run --rm php <cmd>`.
Konsumenci Kafki: `messenger:setup-transports failed` + `messenger:consume {progress_enrollment_in,notification_enrollment_in,notification_progress_in}` (patrz README).


---

## 📋 Backlog na jutro (opcjonalne — MVP działa bez tego)
Kolejność wg „najszybszy widoczny efekt → twardsze hardening":
1. ~~**Wyświetlić % ukończenia w UI**~~ ✅ ZROBIONE (commit 7c8ca48) — `CourseProgress::completionPercentage()` już istnieje i jest przetestowane; brakuje renderu na stronie kursu (np. `templates/catalog/detail.html.twig` lub osobny widok „moje postępy"). Najszybszy, widoczny efekt.
2. ~~**MAILER from-address do configu**~~ ✅ ZROBIONE (303da45) — `SymfonyMailer` ma zahardcodowane `from('platforma@example.com')`; przenieść do env/`framework.mailer.envelope.sender`.
3. ~~**Testy jednostkowe KafkaTransport bez brokera**~~ ✅ ZROBIONE (8e37ff0) — `ack()` rzuca przy braku stampa, guardy `topic`/`consumer_group`, mapowanie nagłówków w `send()`. Round-trip pokryty smoke-testem grupy `kafka`.
4. ~~**CSRF na formularzu „Opublikuj"**~~ ✅ ZROBIONE (4f06756) (Faza 3, `templates/instructor/manage_course.html.twig`) — chroniony rolą ROLE_INSTRUCTOR, więc Minor; dla spójności dodać token jak przy enroll/complete.
5. ~~**(post-MVP) Transactional outbox**~~ ZROBIONE (774e6b6) — domknięcie at-least-once luk save→publish / send→save (świadomie udokumentowane jako trade-off).

Tryb pracy bez zmian: Subagent-Driven, **commity bez śladu AI**. Każdy follow-up = mały plan/task → review → merge.

## Po outboxie — pozostałe nice-to-have (opcjonalne)
- ~~Outbox operability (graceful shutdown + --sleep + app:outbox:status)~~ ZROBIONE (ebbd206)
- `.env.test` dummy secret — oznaczyć w GitGuardian jako false-positive (fixture testowy) lub zgenerycznić wartość.
- Wielo-instancyjny relay (SELECT ... FOR UPDATE SKIP LOCKED) — gdy pojawi się potrzeba skali.
