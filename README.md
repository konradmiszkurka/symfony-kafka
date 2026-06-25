# Training platform (Symfony + Kafka)

A modular monolith (hexagonal + light DDD), event-driven via Kafka.
Architecture overview: [`architecture.md`](architecture.md).
Design spec: `docs/superpowers/specs/2026-06-24-training-platform-kafka-design.md`.

## Requirements
- Docker + Docker Compose (PHP/Composer are NOT needed on the host — they run in the container).

## Getting started
```bash
docker compose build php
docker compose up -d
docker compose run --rm php bin/console doctrine:database:create --if-not-exists
```

## Addresses
| Service | URL |
|---------|-----|
| Application | http://localhost:8080 |
| Health | http://localhost:8080/health |
| Kafka UI | http://localhost:8081 |
| Mailpit | http://localhost:8025 |

## Commands (in the container)
```bash
docker compose exec php bin/console <cmd>     # Symfony console
docker compose exec php vendor/bin/phpunit    # tests
docker compose exec php composer <cmd>        # composer
```

## Structure
`src/<Module>/{Domain,Application,Infrastructure,UI}` — modules: Identity, Catalog, Enrollment, Progress, Notification, Shared.

## Kafka consumers (event-driven)
Events are published to Kafka; consumers are Messenger worker processes (separate consumer groups):
```bash
docker compose run --rm php bin/console messenger:setup-transports failed   # once, dead-letter table
docker compose exec php bin/console messenger:consume progress_enrollment_in -vv      # progress initialization
docker compose exec php bin/console messenger:consume notification_enrollment_in -vv  # welcome email
docker compose exec php bin/console messenger:consume notification_progress_in -vv    # congratulations email
```
Emails are visible in Mailpit (http://localhost:8025), events in Kafka UI (http://localhost:8081).
Failed messages: `bin/console messenger:failed:show`.

### Outbox relay (producer → Kafka)
Domain events first land in the `outbox` table (atomically with the state change). The relay
delivers them to Kafka:
```bash
docker compose exec php bin/console app:outbox:relay -vv              # worker loop (default sleep 1 s)
docker compose exec php bin/console app:outbox:relay --once           # single pass (cron)
docker compose exec php bin/console app:outbox:relay --sleep=5        # worker with a 5-second interval
```

**Graceful shutdown**: the relay handles `SIGTERM` and `SIGINT` via `SignalableCommandInterface`.
On a signal it finishes the current batch iteration, prints `Relay stopped.` and exits with code 0.
It assumes a single relay instance runs at a time (no race-condition locking).

Options:
- `--limit=N` — batch size (default 100)
- `--once` — single pass and exit
- `--sleep=N` — interval in seconds when the batch is empty (default 1)

### Outbox monitoring
```bash
docker compose exec php bin/console app:outbox:status                 # check status (default threshold 5 min)
docker compose exec php bin/console app:outbox:status --stuck-after=2 # stuck threshold of 2 minutes
```

The command prints the number of unsent rows and the number of stuck rows (no `sentAt` and
`createdAt` older than the threshold).
**It returns exit code 1 (FAILURE) when there are stuck rows** — which makes it suitable for
monitoring / cron alerts:
```bash
# in a cron job or healthcheck:
bin/console app:outbox:status --stuck-after=5 || alert "Outbox has stuck rows!"
```
