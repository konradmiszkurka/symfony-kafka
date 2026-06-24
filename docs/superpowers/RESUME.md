# Stan prac — wznowienie (zapis: 2026-06-24)

## Gdzie jesteśmy
Platforma szkoleniowa Symfony 7.4 + Kafka (modularny monolit, hexagonal + lekkie DDD).
Tryb wykonania: **Subagent-Driven Development** (fresh subagent per task + review + merge per faza).
**Reguła twarda: commity BEZ śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code").

## Status faz
- **Faza 1 (Fundament)** — ✅ na `main` (Docker, MySQL 8.4, Kafka KRaft, skeleton, busy, /health)
- **Faza 2 (Identity)** — ✅ na `main` (rejestracja, login, role STUDENT/INSTRUCTOR, /dashboard)
- **Faza 3 (Catalog)** — ✅ na `main` (agregat Course→Section→Lesson, CRUD instruktora, przeglądanie)
- **Faza 4 (Enrollment + producent Kafki)** — ✅ na `main` (własny transport Kafka, zapis na kurs → UserEnrolled)
- **Faza 5 (Progress + Notification konsumenci)** — ⏳ **GOTOWA NA GAŁĘZI, NIEZMERGOWANA**

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
