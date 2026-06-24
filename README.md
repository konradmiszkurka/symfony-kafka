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
