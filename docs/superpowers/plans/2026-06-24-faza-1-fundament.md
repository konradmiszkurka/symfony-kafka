# Faza 1: Fundament i środowisko — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Postawić środowisko deweloperskie (Docker: php+rdkafka, nginx, Postgres, Kafka w trybie KRaft, Kafka UI, Mailpit) oraz szkielet aplikacji Symfony z modularną strukturą katalogów, skonfigurowanymi busami Messengera i działającym endpointem `/health`.

**Architecture:** Modularny monolit Symfony 7.x (PHP 8.4) uruchamiany w kontenerach. PHP/Composer/konsola/testy działają WYŁĄCZNIE wewnątrz kontenera `php` (na hoście nie ma PHP). Struktura `src/<Moduł>/{Domain,Application,Infrastructure,UI}` przygotowana pod kolejne fazy. Kafka działa, ale w tej fazie nieużywana przez aplikację (konfigurację transportu dodaje Faza 4).

**Tech Stack:** PHP 8.4 (fpm-alpine) + ext-rdkafka/intl/pdo_mysql, Symfony 7.x, Doctrine ORM, Symfony Messenger, Twig, PHPUnit, Docker Compose, MySQL 8.4, Apache Kafka (KRaft), kafbat Kafka UI, Mailpit.

> **Zmiana bazy (2026-06-24):** projekt przeszedł z PostgreSQL na **MySQL 8.4** na życzenie użytkownika (komfort/znajomość). Wybór bazy jest niezależny od Kafki — eventy publikuje aplikacja przez Messenger, nie baza. Historyczne bloki kodu poniżej (Task 1/3) wspominają jeszcze Postgresa; obowiązujący stan to MySQL (patrz commity i ledger).

## Global Constraints

- PHP **8.4**, Symfony **7.x** — wersje minimalne, nie obniżać.
- Wszystkie komendy PHP/Composer/console/PHPUnit uruchamiane w kontenerze: `docker compose exec php <cmd>` (lub `docker compose run --rm php <cmd>` gdy kontener nie działa).
- Baza: **MySQL 8.4** (nie Postgres/SQLite w środowisku dev).
- Kafka w trybie **KRaft** (bez Zookeepera).
- Struktura kodu: `src/<BoundedContext>/{Domain,Application,Infrastructure,UI}`. Namespace PSR-4: `App\<BoundedContext>\...`.
- Domena nie zależy od frameworka — w tej fazie nie dotyczy jeszcze kodu domenowego, ale strukturę utrzymujemy.
- Każdy task kończy się commitem.

---

## Struktura plików (tworzona w tej fazie)

```
compose.yaml                      # definicja usług Docker
.dockerignore
docker/
  php/
    Dockerfile                    # php:8.4-fpm-alpine + rdkafka, intl, pdo_pgsql
    php.ini                       # ustawienia php (opcache, timezone)
  nginx/
    default.conf                  # vhost Symfony
src/                              # (skeleton Symfony nadpisze część)
  Shared/
    Application/
      Bus/
        CommandBusInterface.php   # marker portu (na przyszłość, cienki)
  Identity/   Catalog/   Enrollment/   Progress/   Notification/
    (puste katalogi z .gitkeep — wypełniane w kolejnych fazach)
  Controller/
    HealthController.php          # GET /health
config/
  packages/messenger.yaml         # busy: command.bus, query.bus (sync na razie)
tests/
  Functional/
    HealthControllerTest.php
```

> Uwaga: `composer create-project symfony/skeleton` wygeneruje m.in. `composer.json`, `bin/console`, `public/index.php`, `config/`, `src/Kernel.php`, `.env`. Pliki Docker i `docs/` już istnieją — bootstrap nie może ich nadpisać (Task 2 używa `cp -rn`, czyli „no-clobber").

---

### Task 1: Środowisko Docker (php, nginx, Postgres, Kafka, Kafka UI, Mailpit)

Cel: `docker compose up -d` stawia komplet usług; kontenery zdrowe; Kafka UI i Mailpit dostępne w przeglądarce. (Task infrastrukturalny — weryfikacja przez output komend, nie testy jednostkowe.)

**Files:**
- Create: `docker/php/Dockerfile`
- Create: `docker/php/php.ini`
- Create: `docker/nginx/default.conf`
- Create: `compose.yaml`
- Create: `.dockerignore`

**Interfaces:**
- Produces: usługa `php` (kontener z PHP 8.4 + composer + rdkafka, working dir `/app`, montuje katalog projektu), usługa `nginx` (port 8080→80), `postgres` (5432, db `app`, user `app`, hasło `app`), `kafka` (broker `kafka:9092` wewnątrz sieci, `localhost:29092` z hosta), `kafka-ui` (port 8081), `mailpit` (SMTP `mailpit:1025`, UI port 8025).

- [ ] **Step 1: Utwórz `docker/php/Dockerfile`**

```dockerfile
FROM php:8.4-fpm-alpine

# Zależności systemowe + rozszerzenia PHP
RUN apk add --no-cache \
        librdkafka-dev \
        icu-dev \
        postgresql-dev \
        git \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_pgsql opcache \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apk del .build-deps

# Composer z oficjalnego obrazu
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
```

- [ ] **Step 2: Utwórz `docker/php/php.ini`**

```ini
date.timezone = Europe/Warsaw
memory_limit = 512M

opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
```

- [ ] **Step 3: Utwórz `docker/nginx/default.conf`**

```nginx
server {
    listen 80;
    server_name _;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    error_log /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
}
```

- [ ] **Step 4: Utwórz `compose.yaml`**

```yaml
services:
  php:
    build:
      context: ./docker/php
    volumes:
      - ./:/app
    depends_on:
      - postgres
      - kafka

  nginx:
    image: nginx:1.27-alpine
    ports:
      - "8080:80"
    volumes:
      - ./:/app
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: app
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data

  kafka:
    image: apache/kafka:3.9.0
    ports:
      - "29092:29092"
    environment:
      KAFKA_NODE_ID: 1
      KAFKA_PROCESS_ROLES: broker,controller
      KAFKA_CONTROLLER_QUORUM_VOTERS: 1@kafka:9093
      KAFKA_LISTENERS: PLAINTEXT://0.0.0.0:9092,CONTROLLER://0.0.0.0:9093,EXTERNAL://0.0.0.0:29092
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:9092,EXTERNAL://localhost:29092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,CONTROLLER:PLAINTEXT,EXTERNAL:PLAINTEXT
      KAFKA_CONTROLLER_LISTENER_NAMES: CONTROLLER
      KAFKA_INTER_BROKER_LISTENER_NAME: PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS: 0

  kafka-ui:
    image: ghcr.io/kafbat/kafka-ui:latest
    ports:
      - "8081:8080"
    environment:
      KAFKA_CLUSTERS_0_NAME: local
      KAFKA_CLUSTERS_0_BOOTSTRAPSERVERS: kafka:9092
      DYNAMIC_CONFIG_ENABLED: "true"
    depends_on:
      - kafka

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  pgdata:
```

- [ ] **Step 5: Utwórz `.dockerignore`**

```
.git
var/
vendor/
node_modules/
docs/
```

- [ ] **Step 6: Zbuduj obraz php i wystartuj usługi infrastrukturalne**

Run: `docker compose build php`
Expected: build kończy się sukcesem; w logach widać instalację `rdkafka` (komunikat `Build process completed successfully` z pecl).

Run: `docker compose up -d postgres kafka kafka-ui mailpit`
Expected: `docker compose ps` pokazuje 4 usługi w stanie `running`.

- [ ] **Step 7: Zweryfikuj rdkafka w kontenerze php**

Run: `docker compose run --rm php php -m | grep rdkafka`
Expected: wypisuje `rdkafka`.

- [ ] **Step 8: Zweryfikuj dostępność Kafki i Mailpit**

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081`
Expected: `200` (Kafka UI odpowiada).

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8025`
Expected: `200` (Mailpit UI odpowiada).

- [ ] **Step 9: Commit**

```bash
git add docker/ compose.yaml .dockerignore
git commit -m "feat(infra): docker compose — php+rdkafka, nginx, postgres, kafka KRaft, kafka-ui, mailpit"
```

---

### Task 2: Bootstrap szkieletu Symfony + zależności

Cel: w katalogu projektu znajduje się działający skeleton Symfony z potrzebnymi paczkami; `bin/console about` działa w kontenerze.

**Files:**
- Create (przez composer): `composer.json`, `composer.lock`, `bin/console`, `public/index.php`, `src/Kernel.php`, `config/*`, `.env`
- Modify: `.gitignore` (skeleton go utworzy — zweryfikować, że ignoruje `/vendor`, `/var`)

**Interfaces:**
- Consumes: usługa `php` z Taska 1.
- Produces: działający Symfony Kernel (`App\Kernel`), Composer z zainstalowanymi: `symfony/orm-pack`, `symfony/messenger`, `twig`, `symfony/security-bundle`, `symfony/maker-bundle` (dev), `symfony/test-pack` (dev).

- [ ] **Step 1: Wygeneruj skeleton do katalogu tymczasowego i skopiuj bez nadpisywania istniejących plików**

Run:
```bash
docker compose run --rm php sh -c "composer create-project symfony/skeleton:'7.*' /tmp/sk --no-interaction && cp -rn /tmp/sk/. /app/ && rm -rf /tmp/sk"
```
Expected: pliki `composer.json`, `bin/console`, `public/index.php`, `src/Kernel.php`, `.env` pojawiają się w katalogu projektu; istniejące `docker/`, `docs/`, `compose.yaml` NIE są nadpisane.

> `cp -rn` (no-clobber) nie nadpisze plików, które już istnieją. Skeleton nie zawiera kolidujących nazw z naszymi plikami Docker, więc kopiowanie jest bezpieczne.

- [ ] **Step 2: Zainstaluj zależności aplikacyjne**

Run:
```bash
docker compose run --rm php composer require symfony/orm-pack symfony/messenger twig symfony/security-bundle --no-interaction
```
Expected: instalacja kończy się sukcesem; `composer.json` zawiera te paczki w `require`.

- [ ] **Step 3: Zainstaluj zależności deweloperskie (maker + test-pack)**

Run:
```bash
docker compose run --rm php composer require --dev symfony/maker-bundle symfony/test-pack --no-interaction
```
Expected: sukces; `phpunit` dostępny w `vendor/bin/phpunit`.

- [ ] **Step 4: Zweryfikuj działanie konsoli**

Run: `docker compose run --rm php bin/console about`
Expected: wypisuje informacje o środowisku, `Symfony version 7.x`, `PHP version 8.4.x`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: bootstrap szkieletu Symfony 7 + orm, messenger, twig, security, maker, test-pack"
```

---

### Task 3: Konfiguracja bazy danych (Postgres) + migracje

Cel: aplikacja łączy się z Postgresem; narzędzie migracji działa; baza testowa skonfigurowana.

**Files:**
- Modify: `.env` (DATABASE_URL)
- Create: `.env.test` (jeśli skeleton nie utworzył — lub modyfikacja)
- Modify: `config/packages/doctrine.yaml` (zwykle nie trzeba — DSN z env wystarcza)

**Interfaces:**
- Consumes: usługa `postgres` (Task 1), Doctrine (Task 2).
- Produces: działające połączenie z bazą `app`; komenda `doctrine:migrations:*` operacyjna.

- [ ] **Step 1: Ustaw `DATABASE_URL` w `.env`**

Znajdź linię `DATABASE_URL=` (dodaną przez orm-pack) i ustaw na:

```dotenv
DATABASE_URL="postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8"
```

- [ ] **Step 2: Skonfiguruj bazę testową w `.env.test`**

Dopisz na końcu `.env.test`:

```dotenv
DATABASE_URL="postgresql://app:app@postgres:5432/app_test?serverVersion=16&charset=utf8"
```

- [ ] **Step 3: Zweryfikuj połączenie z bazą**

Run: `docker compose run --rm php bin/console dbal:run-sql "SELECT 1"`
Expected: zwraca wynik `1` bez błędu połączenia.

- [ ] **Step 4: Utwórz bazę testową**

Run: `docker compose run --rm php bin/console doctrine:database:create --env=test --if-not-exists`
Expected: `Created database ... app_test` lub informacja, że już istnieje.

- [ ] **Step 5: Commit**

```bash
git add .env .env.test
git commit -m "feat(db): konfiguracja połączenia Postgres (dev + test)"
```

---

### Task 4: Modularna struktura katalogów + mapowanie PSR-4

Cel: katalogi modułów istnieją i są mapowane w autoloaderze; `App\Identity\`, `App\Catalog\`, `App\Enrollment\`, `App\Progress\`, `App\Notification\`, `App\Shared\` rozpoznawane.

**Files:**
- Create: `src/Identity/.gitkeep`, `src/Catalog/.gitkeep`, `src/Enrollment/.gitkeep`, `src/Progress/.gitkeep`, `src/Notification/.gitkeep`
- Create: `src/Shared/Application/Bus/CommandBusInterface.php`
- Modify: `composer.json` (autoload — zwykle `App\\: src/` już pokrywa wszystko; sprawdzić)

**Interfaces:**
- Produces: namespace `App\Shared\Application\Bus\CommandBusInterface` (marker portu busa komend; implementacja w Task 5).

- [ ] **Step 1: Utwórz katalogi modułów z plikami `.gitkeep`**

Run:
```bash
for m in Identity Catalog Enrollment Progress Notification; do mkdir -p "src/$m" && touch "src/$m/.gitkeep"; done
mkdir -p src/Shared/Application/Bus
```
Expected: katalogi `src/Identity` … `src/Notification` oraz `src/Shared/Application/Bus` istnieją.

- [ ] **Step 2: Utwórz `src/Shared/Application/Bus/CommandBusInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface CommandBusInterface
{
    public function dispatch(object $command): void;
}
```

- [ ] **Step 3: Zweryfikuj autoload**

Run: `docker compose run --rm php composer dump-autoload`
Expected: sukces, brak ostrzeżeń o niezmapowanych klasach.

Run: `docker compose run --rm php php -r "require 'vendor/autoload.php'; var_dump(interface_exists('App\\Shared\\Application\\Bus\\CommandBusInterface'));"`
Expected: `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add src/ composer.json
git commit -m "feat: modularna struktura katalogów + port CommandBusInterface"
```

---

### Task 5: Busy Messengera (command.bus, query.bus, sync)

Cel: skonfigurowane dwa busy synchroniczne; `CommandBusInterface` zwiąż z `command.bus`. Transport Kafka NIE jest tu konfigurowany (Faza 4).

**Files:**
- Modify: `config/packages/messenger.yaml`
- Create: `src/Shared/Infrastructure/Bus/MessengerCommandBus.php`
- Modify: `config/services.yaml` (alias interfejsu → implementacja; zwykle autowiring wystarcza, dodajemy jawne wiązanie busa)

**Interfaces:**
- Consumes: `App\Shared\Application\Bus\CommandBusInterface` (Task 4), `Symfony\Component\Messenger\MessageBusInterface`.
- Produces: serwis `MessengerCommandBus implements CommandBusInterface` opakowujący `command.bus`; dostępne busy DI: `command.bus`, `query.bus`.

- [ ] **Step 1: Skonfiguruj busy w `config/packages/messenger.yaml`**

Zastąp zawartość pliku (sekcja `framework.messenger`) tak, by zawierała:

```yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus:
                middleware:
                    - validation
            query.bus:
                middleware:
                    - validation
        # Transporty (Kafka) dodaje Faza 4.
```

- [ ] **Step 2: Utwórz adapter `src/Shared/Infrastructure/Bus/MessengerCommandBus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBusInterface
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function dispatch(object $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            // Odpakuj oryginalny wyjątek domenowy z opakowania Messengera.
            throw $e->getPrevious() ?? $e;
        }
    }
}
```

- [ ] **Step 3: Zwiąż interfejs z implementacją i bus w `config/services.yaml`**

W sekcji `services:` (po domyślnym `_defaults` i autoconfigure) dodaj:

```yaml
    App\Shared\Infrastructure\Bus\MessengerCommandBus:
        arguments:
            $commandBus: '@command.bus'

    App\Shared\Application\Bus\CommandBusInterface:
        alias: App\Shared\Infrastructure\Bus\MessengerCommandBus
```

- [ ] **Step 4: Zweryfikuj konfigurację kontenera**

Run: `docker compose run --rm php bin/console debug:container CommandBusInterface`
Expected: pokazuje alias `App\Shared\Application\Bus\CommandBusInterface` → `MessengerCommandBus`.

Run: `docker compose run --rm php bin/console lint:container`
Expected: `The container was linted successfully` (brak błędów wiązań).

- [ ] **Step 5: Commit**

```bash
git add config/ src/Shared/
git commit -m "feat(shared): busy Messengera (command/query, sync) + adapter CommandBus"
```

---

### Task 6: Endpoint `/health` (TDD)

Cel: `GET /health` zwraca `200` i JSON `{"status":"ok"}`. Pełen cykl TDD + test funkcjonalny przez `WebTestCase`.

**Files:**
- Test: `tests/Functional/HealthControllerTest.php`
- Create: `src/Controller/HealthController.php`
- Modify: `phpunit.xml.dist` (skeleton tworzy — zwykle bez zmian)

**Interfaces:**
- Consumes: Symfony Routing, `WebTestCase` (test-pack z Task 2).
- Produces: trasa `health` (`GET /health`) → `JsonResponse`.

- [ ] **Step 1: Napisz failujący test `tests/Functional/HealthControllerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOkJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $data['status']);
    }
}
```

- [ ] **Step 2: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/HealthControllerTest.php`
Expected: FAIL — `404 Not Found` (trasa `/health` nie istnieje).

- [ ] **Step 3: Zaimplementuj `src/Controller/HealthController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 4: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/HealthControllerTest.php`
Expected: PASS (1 test, 3 assertions).

- [ ] **Step 5: Zweryfikuj endpoint przez HTTP (cały stack)**

Run: `docker compose up -d php nginx`
Run: `curl -s http://localhost:8080/health`
Expected: `{"status":"ok"}`

- [ ] **Step 6: Commit**

```bash
git add tests/ src/Controller/
git commit -m "feat: endpoint /health + test funkcjonalny"
```

---

### Task 7: README — uruchamianie środowiska

Cel: udokumentować jak postawić i obsługiwać środowisko (dla przyszłego dewelopera / siebie za 3 miesiące).

**Files:**
- Modify: `README.md`

**Interfaces:** brak (dokumentacja).

- [ ] **Step 1: Wypełnij `README.md`**

```markdown
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
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: README — uruchamianie środowiska deweloperskiego"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu (sekcja 7 — Stack i infrastruktura):**
- PHP 8.4 + Symfony 7.x → Task 1 (Dockerfile), Task 2 (skeleton) ✅
- Doctrine ORM → Task 2 (orm-pack), Task 3 (config) ✅
- Symfony Messenger → Task 2 (instalacja), Task 5 (busy) ✅
- Twig, Security → Task 2 (instalacja; użycie w Fazie 2–3) ✅
- php-rdkafka → Task 1 (Dockerfile + weryfikacja `php -m`) ✅
- PostgreSQL → Task 1 (usługa), Task 3 (połączenie) ✅
- Docker Compose: php+nginx, postgres, kafka KRaft, kafka-ui, mailpit → Task 1 ✅
- Struktura katalogów (spec sekcja 8) → Task 4 ✅

**Zakres tej fazy:** tylko fundament. Logika domenowa (Identity/Catalog/Enrollment/Progress/Notification) oraz transport Kafka są celowo poza tą fazą — realizują je Fazy 2–5. To świadoma granica, nie luka.

**Placeholdery:** brak „TBD/TODO" w krokach wykonawczych; każdy krok ma konkretną treść/komendę.

**Spójność typów:** `CommandBusInterface::dispatch(object): void` (Task 4) zgodny z implementacją `MessengerCommandBus` (Task 5). Alias DI wiąże interfejs z implementacją (Task 5 Step 3).

**Uwaga wykonawcza:** wersje obrazów (`apache/kafka:3.9.0`, `kafbat/kafka-ui:latest`) i `symfony/skeleton:'7.*'` należy potraktować jako aktualne na dziś — jeśli build którejś usługi zawiedzie przez nieistniejący tag, wybrać najbliższy dostępny stabilny tag i odnotować w commicie.
