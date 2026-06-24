# Faza 4: Enrollment + producent Kafki — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Student zapisuje się na opublikowany kurs; zapis publikuje integration event `UserEnrolled` na topic Kafki (widoczny w Kafka UI) — przez **własny transport Kafka dla Symfony Messenger** na `php-rdkafka`. Podwójny zapis jest blokowany.

**Architecture:** Bounded context `Enrollment` (Domain/Application/Infrastructure/UI). Integration eventy to wiadomości Messengera routowane do własnego transportu Kafka. Producent = `KafkaTransport::send()` (RdKafka\Producer). Cross-module: Enrollment sprawdza dostępność kursu przez port `CourseAvailabilityChecker` (adapter pyta Catalog przez QueryBus). Testy używają transportu `in-memory` (asercja wysłanych eventów); jeden smoke-test sprawdza realny round-trip przez Kafkę.

**Tech Stack:** Symfony 7.4 Messenger (własny TransportFactory/Transport), php-rdkafka (już w obrazie), symfony/serializer (JSON eventów), Doctrine ORM + migrations, symfony/uid, Twig, PHPUnit, DAMA.

## Global Constraints

- PHP **8.4**, Symfony **7.x**; baza **MySQL 8.4**; broker **kafka:9092** (w sieci Dockera), `localhost:29092` z hosta. Komendy w kontenerze: `docker compose run --rm php <cmd>`. Usługi: `docker compose up -d mysql kafka`.
- Namespace PSR-4: `App\Enrollment\{Domain,Application,Infrastructure,UI}`; transport w `App\Shared\Infrastructure\Messenger\Kafka`. **Domain/Application bez zależności od frameworka** (dozwolone: atrybuty `#[ORM\...]`, `#[AsMessageHandler]`).
- Integracja Kafki: **eventy = wiadomości Messengera**, routowane do transportu `kafka_events`. Produkcja przez własny `KafkaTransport`. **Testy na transporcie `in-memory`**; realnej Kafki dotyka tylko smoke-test (grupa `kafka`, wyłączona z domyślnego runu).
- Spójność: event publikowany PO `flush()` zapisu (bez pełnego outboxa — zgodnie ze specem §3).
- Granica modułów: Enrollment trzyma `userId`/`courseId` jako `Uuid`, nie encje. Dostęp do Catalog tylko przez port + adapter (warstwa Infrastructure).
- Testy: piramida; pristine output (0 deprecations/warningów/PHPUnit notices); izolacja DB (DAMA). Mocki bez oczekiwań dają notice → używać `createStub`/anonimowych fake'ów.
- **Commity bez śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code"). Git user: `konrad`.
- Każdy task kończy się commitem.

---

## Struktura plików

```
src/Shared/
  Infrastructure/Messenger/Kafka/
    KafkaTransportFactory.php
    KafkaTransport.php
    KafkaMessageStamp.php
  Application/Bus/EventBusInterface.php
  Infrastructure/Bus/MessengerEventBus.php
src/Enrollment/
  Domain/
    Enrollment.php
    EnrollmentRepository.php
    Event/UserEnrolled.php
    Exception/AlreadyEnrolledException.php
    Exception/CourseNotEnrollableException.php
  Application/
    CourseAvailabilityChecker.php          # port
    EnrollStudent/EnrollStudentCommand.php
    EnrollStudent/EnrollStudentHandler.php
  Infrastructure/
    Doctrine/DoctrineEnrollmentRepository.php
    Catalog/CatalogCourseAvailabilityChecker.php   # adapter -> Catalog QueryBus
  UI/Controller/EnrollController.php
config/packages/messenger.yaml             # event.bus, transport kafka_events, routing
config/packages/doctrine.yaml              # mapowanie Enrollment
config/services.yaml                       # aliasy + tag factory
config/routes.yaml                         # routing Enrollment
.env / .env.test                           # MESSENGER_KAFKA_EVENTS_DSN
migrations/Version*.php                     # enrollments
templates/catalog/detail.html.twig         # przycisk „Zapisz się"
tests/Unit/Shared/Kafka/...  tests/Smoke/Kafka/...  tests/Integration/Enrollment/...  tests/Functional/Enrollment/...
```

---

### Task 1: Własny transport Kafka dla Messengera (factory + transport + stamp)

**Files:**
- Create: `src/Shared/Infrastructure/Messenger/Kafka/KafkaMessageStamp.php`
- Create: `src/Shared/Infrastructure/Messenger/Kafka/KafkaTransport.php`
- Create: `src/Shared/Infrastructure/Messenger/Kafka/KafkaTransportFactory.php`
- Modify: `config/services.yaml` (tag `messenger.transport_factory`)
- Test: `tests/Unit/Shared/Kafka/KafkaTransportFactoryTest.php`

**Interfaces:**
- Produces:
  - `KafkaTransportFactory implements Symfony\Component\Messenger\Transport\TransportFactoryInterface` — `supports(string $dsn, array $options): bool` (true gdy `str_starts_with($dsn, 'kafka://')`), `createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface`.
  - `KafkaTransport implements TransportInterface` (Sender+Receiver) — produkuje przez `RdKafka\Producer`, konsumuje przez `RdKafka\KafkaConsumer` (group.id, manualny commit na `ack`).
  - `KafkaMessageStamp implements NonSendableStampInterface` — niesie `RdKafka\Message` do commitu offsetu.

- [ ] **Step 1: Utwórz `KafkaMessageStamp.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class KafkaMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly \RdKafka\Message $message)
    {
    }
}
```

- [ ] **Step 2: Utwórz `KafkaTransport.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransport implements TransportInterface
{
    private ?\RdKafka\Producer $producer = null;
    private ?\RdKafka\KafkaConsumer $consumer = null;

    /**
     * @param array{topic?: string, consumer_group?: string, auto_offset_reset?: string, consume_timeout_ms?: int} $options
     */
    public function __construct(
        private readonly string $brokers,
        private readonly array $options,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        $encoded = $this->serializer->encode($envelope);

        $headers = [];
        foreach (($encoded['headers'] ?? []) as $name => $value) {
            $headers[$name] = (string) $value;
        }

        $producer = $this->producer();
        $topic = $producer->newTopic($this->topic());
        $topic->producev(RD_KAFKA_PARTITION_UA, 0, $encoded['body'], null, $headers);
        $producer->poll(0);

        $result = $producer->flush(10000);
        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new TransportException('Nie udało się wysłać wiadomości do Kafki (flush).');
        }

        return $envelope;
    }

    public function get(): iterable
    {
        $message = $this->consumer()->consume($this->options['consume_timeout_ms'] ?? 1000);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                $envelope = $this->serializer->decode([
                    'body' => $message->payload,
                    'headers' => $message->headers ?? [],
                ]);

                return [$envelope->with(new KafkaMessageStamp($message))];
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                return [];
            default:
                throw new TransportException($message->errstr());
        }
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $envelope->last(KafkaMessageStamp::class);
        if ($stamp instanceof KafkaMessageStamp) {
            $this->consumer()->commit($stamp->message);
        }
    }

    public function reject(Envelope $envelope): void
    {
        // At-least-once: brak commitu => wiadomość zostanie dostarczona ponownie.
    }

    private function topic(): string
    {
        return $this->options['topic'] ?? throw new TransportException('Brak opcji "topic" dla transportu Kafka.');
    }

    private function producer(): \RdKafka\Producer
    {
        if (null === $this->producer) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $this->producer = new \RdKafka\Producer($conf);
        }

        return $this->producer;
    }

    private function consumer(): \RdKafka\KafkaConsumer
    {
        if (null === $this->consumer) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $conf->set('group.id', $this->options['consumer_group'] ?? 'symfony-kafka-app');
            $conf->set('auto.offset.reset', $this->options['auto_offset_reset'] ?? 'earliest');
            $conf->set('enable.auto.commit', 'false');
            $consumer = new \RdKafka\KafkaConsumer($conf);
            $consumer->subscribe([$this->topic()]);
            $this->consumer = $consumer;
        }

        return $this->consumer;
    }
}
```

- [ ] **Step 3: Utwórz `KafkaTransportFactory.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransportFactory implements TransportFactoryInterface
{
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'kafka://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $brokers = substr($dsn, \strlen('kafka://'));
        // Odetnij ewentualny query string z DSN (opcje przychodzą przez $options).
        $brokers = explode('?', $brokers)[0];

        unset($options['transport_name']);

        return new KafkaTransport($brokers, $options, $serializer);
    }
}
```

- [ ] **Step 4: Otaguj factory w `config/services.yaml`**

Dodaj (autoconfigure zwykle taguje implementacje `TransportFactoryInterface`, ale dodajemy jawnie dla pewności):

```yaml
    App\Shared\Infrastructure\Messenger\Kafka\KafkaTransportFactory:
        tags:
            - { name: messenger.transport_factory }
```

- [ ] **Step 5: Napisz test `tests/Unit/Shared/Kafka/KafkaTransportFactoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Kafka;

use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransport;
use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class KafkaTransportFactoryTest extends TestCase
{
    public function testSupportsKafkaDsnOnly(): void
    {
        $factory = new KafkaTransportFactory();

        self::assertTrue($factory->supports('kafka://kafka:9092', []));
        self::assertFalse($factory->supports('in-memory://', []));
        self::assertFalse($factory->supports('doctrine://default', []));
    }

    public function testCreatesKafkaTransport(): void
    {
        $factory = new KafkaTransportFactory();
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $factory->createTransport('kafka://kafka:9092', ['topic' => 'enrollment.events'], $serializer);

        self::assertInstanceOf(KafkaTransport::class, $transport);
    }
}
```

- [ ] **Step 6: Uruchom test**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Shared/Kafka/KafkaTransportFactoryTest.php`
Expected: PASS (2 testy), pristine. (Najpierw FAIL przed utworzeniem klas — uruchom po Step 1-3.)

- [ ] **Step 7: Commit**

```bash
git add src/Shared/Infrastructure/Messenger/Kafka/ config/services.yaml tests/Unit/Shared/Kafka/
git commit -m "feat(shared): własny transport Kafka dla Messengera (producer + consumer na php-rdkafka)"
```

---

### Task 2: Wiring Messengera — serializer JSON, event.bus, transport kafka_events, routing

**Files:**
- Create: `src/Shared/Application/Bus/EventBusInterface.php`
- Create: `src/Shared/Infrastructure/Bus/MessengerEventBus.php`
- Modify: `config/packages/messenger.yaml`
- Modify: `config/services.yaml`
- Modify: `.env`, `.env.test`

**Interfaces:**
- Consumes: `KafkaTransport*` (Task 1).
- Produces: `interface App\Shared\Application\Bus\EventBusInterface { public function publish(object $event): void; }`; bus `event.bus` (allow_no_handlers); transport `kafka_events` (DSN z env, serializer JSON, topic `enrollment.events`).

- [ ] **Step 1: Zainstaluj symfony/serializer (JSON eventów w Kafce)**

Run: `docker compose run --rm php composer require symfony/serializer --no-interaction`
Expected: sukces; dostępny serializer Messengera `messenger.transport.symfony_serializer`.

- [ ] **Step 2: Skonfiguruj `config/packages/messenger.yaml`**

Ustaw sekcję `framework.messenger` tak:

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
            event.bus:
                default_middleware:
                    enabled: true
                    allow_no_handlers: true
                middleware:
                    - validation
        transports:
            kafka_events:
                dsn: '%env(MESSENGER_KAFKA_EVENTS_DSN)%'
                serializer: messenger.transport.symfony_serializer
                options:
                    topic: enrollment.events
                    consumer_group: '%env(APP_ENV)%-enrollment-consumer'
        routing:
            'App\Enrollment\Domain\Event\UserEnrolled': kafka_events
```

> `allow_no_handlers: true` na `event.bus` — strona producenta nie ma lokalnego handlera (konsumenci to Faza 5). Routing wysyła event na transport `kafka_events` (asynchronicznie).

- [ ] **Step 3: Ustaw DSN w `.env`**

Dopisz w `.env` (sekcja messenger):

```dotenv
MESSENGER_KAFKA_EVENTS_DSN=kafka://kafka:9092
```

- [ ] **Step 4: Nadpisz transport na in-memory w `.env.test`**

Dopisz w `.env.test`:

```dotenv
MESSENGER_KAFKA_EVENTS_DSN=in-memory://
```

(Dzięki temu testy nie dotykają realnej Kafki; wysłane eventy sprawdzamy przez `InMemoryTransport::getSent()`.)

- [ ] **Step 5: Utwórz port `src/Shared/Application/Bus/EventBusInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface EventBusInterface
{
    public function publish(object $event): void;
}
```

- [ ] **Step 6: Utwórz adapter `src/Shared/Infrastructure/Bus/MessengerEventBus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\EventBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEventBus implements EventBusInterface
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publish(object $event): void
    {
        try {
            $this->eventBus->dispatch($event);
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }
}
```

- [ ] **Step 7: Zwiąż w `config/services.yaml`**

```yaml
    App\Shared\Infrastructure\Bus\MessengerEventBus:
        arguments:
            $eventBus: '@event.bus'

    App\Shared\Application\Bus\EventBusInterface:
        alias: App\Shared\Infrastructure\Bus\MessengerEventBus
```

- [ ] **Step 8: Zweryfikuj kontener i routing**

Run: `docker compose run --rm php bin/console lint:container`
Expected: `The container was linted successfully`.

Run: `docker compose run --rm php bin/console debug:messenger`
Expected: widoczny bus `event.bus`; brak błędów.

Run: `docker compose run --rm php bin/console messenger:setup-transports kafka_events 2>&1 || true`
Expected: brak fatala (transport rozpoznany). (Kafka tworzy topiki automatycznie przy pierwszej produkcji.)

- [ ] **Step 9: Commit**

```bash
git add src/Shared/Application/Bus/EventBusInterface.php src/Shared/Infrastructure/Bus/MessengerEventBus.php config/ .env .env.test composer.json composer.lock symfony.lock
git commit -m "feat(shared): event.bus + transport kafka_events (JSON) + EventBus, in-memory w testach"
```

---

### Task 3: Domena Enrollment — encja, event UserEnrolled, port repo, mapowanie, migracja

**Files:**
- Modify: `config/packages/doctrine.yaml` (mapowanie Enrollment)
- Create: `src/Enrollment/Domain/Enrollment.php`
- Create: `src/Enrollment/Domain/EnrollmentRepository.php`
- Create: `src/Enrollment/Domain/Event/UserEnrolled.php`
- Create: `src/Enrollment/Domain/Exception/AlreadyEnrolledException.php`
- Create: `src/Enrollment/Domain/Exception/CourseNotEnrollableException.php`
- Create: `tests/Unit/Enrollment/Domain/EnrollmentTest.php`
- Create: `migrations/Version*.php`

**Interfaces:**
- Produces:
  - `Enrollment`: `static enroll(Uuid $id, Uuid $userId, Uuid $courseId, \DateTimeImmutable $at): self`; gettery `getId/getUserId/getCourseId/getEnrolledAt`.
  - `interface EnrollmentRepository { public function save(Enrollment $e): void; public function exists(Uuid $userId, Uuid $courseId): bool; }`
  - `UserEnrolled` (readonly, pola string — serializowalne do JSON): `__construct(public string $userId, public string $courseId, public string $occurredAt)`.
  - wyjątki `AlreadyEnrolledException`, `CourseNotEnrollableException`.

- [ ] **Step 1: Dodaj mapowanie w `config/packages/doctrine.yaml`**

W `doctrine.orm.mappings` dopisz:

```yaml
            Enrollment:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Enrollment/Domain'
                prefix: 'App\Enrollment\Domain'
                alias: Enrollment
```

> Uwaga: katalog `Domain` zawiera też `Event/` i `Exception/` (nie-encje) — to OK, Doctrine zignoruje klasy bez atrybutu `#[ORM\Entity]`.

- [ ] **Step 2: Utwórz event `src/Enrollment/Domain/Event/UserEnrolled.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Event;

final readonly class UserEnrolled
{
    public function __construct(
        public string $userId,
        public string $courseId,
        public string $occurredAt,
    ) {
    }
}
```

- [ ] **Step 3: Utwórz wyjątki**

`src/Enrollment/Domain/Exception/AlreadyEnrolledException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Exception;

final class AlreadyEnrolledException extends \DomainException
{
    public static function create(): self
    {
        return new self('Jesteś już zapisany na ten kurs.');
    }
}
```

`src/Enrollment/Domain/Exception/CourseNotEnrollableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Exception;

final class CourseNotEnrollableException extends \DomainException
{
    public static function create(): self
    {
        return new self('Na ten kurs nie można się zapisać.');
    }
}
```

- [ ] **Step 4: Utwórz port `src/Enrollment/Domain/EnrollmentRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use Symfony\Component\Uid\Uuid;

interface EnrollmentRepository
{
    public function save(Enrollment $enrollment): void;

    public function exists(Uuid $userId, Uuid $courseId): bool;
}
```

- [ ] **Step 5: Napisz test `tests/Unit/Enrollment/Domain/EnrollmentTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enrollment\Domain;

use App\Enrollment\Domain\Enrollment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollmentTest extends TestCase
{
    public function testEnrollCapturesUserCourseAndTime(): void
    {
        $userId = Uuid::v4();
        $courseId = Uuid::v4();
        $at = new \DateTimeImmutable('2026-06-24T10:00:00+00:00');

        $enrollment = Enrollment::enroll(Uuid::v4(), $userId, $courseId, $at);

        self::assertTrue($enrollment->getUserId()->equals($userId));
        self::assertTrue($enrollment->getCourseId()->equals($courseId));
        self::assertSame($at, $enrollment->getEnrolledAt());
    }
}
```

- [ ] **Step 6: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Enrollment/Domain/EnrollmentTest.php`
Expected: FAIL — `Enrollment` nie istnieje.

- [ ] **Step 7: Utwórz encję `src/Enrollment/Domain/Enrollment.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_user_course', columns: ['user_id', 'course_id'])]
class Enrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $courseId;

    #[ORM\Column]
    private \DateTimeImmutable $enrolledAt;

    private function __construct(Uuid $id, Uuid $userId, Uuid $courseId, \DateTimeImmutable $enrolledAt)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->enrolledAt = $enrolledAt;
    }

    public static function enroll(Uuid $id, Uuid $userId, Uuid $courseId, \DateTimeImmutable $at): self
    {
        return new self($id, $userId, $courseId, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCourseId(): Uuid
    {
        return $this->courseId;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }
}
```

- [ ] **Step 8: Uruchom test + walidacja mapowania**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Enrollment/Domain/EnrollmentTest.php`
Expected: PASS (1 test), pristine.

Run: `docker compose up -d mysql && docker compose run --rm php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 9: Migracja (tabela enrollments + unikat)**

Run: `docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction`
Expected: `migrations/Version*.php` z `CREATE TABLE enrollments` (id/user_id/course_id BINARY(16), enrolled_at, UNIQUE (user_id, course_id)). Uzupełnij `getDescription()` jednolinijkowo.

Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction`
Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction --env=test`
Expected: zastosowane (dev + test).

- [ ] **Step 10: Commit**

```bash
git add src/Enrollment/Domain/ tests/Unit/Enrollment/ config/packages/doctrine.yaml migrations/
git commit -m "feat(enrollment): encja Enrollment + event UserEnrolled + port repo, mapowanie i migracja"
```

---

### Task 4: Application + Infrastructure — EnrollStudent + publikacja eventu + adapter Catalog

**Files:**
- Create: `src/Enrollment/Application/CourseAvailabilityChecker.php`
- Create: `src/Enrollment/Application/EnrollStudent/EnrollStudentCommand.php`
- Create: `src/Enrollment/Application/EnrollStudent/EnrollStudentHandler.php`
- Create: `src/Enrollment/Infrastructure/Doctrine/DoctrineEnrollmentRepository.php`
- Create: `src/Enrollment/Infrastructure/Catalog/CatalogCourseAvailabilityChecker.php`
- Modify: `config/services.yaml` (aliasy)
- Create: `tests/Unit/Enrollment/Application/EnrollStudentHandlerTest.php`
- Create: `tests/Integration/Enrollment/EnrollStudentIntegrationTest.php`

**Interfaces:**
- Consumes: `Enrollment`, `EnrollmentRepository`, `UserEnrolled`, wyjątki (Task 3), `EventBusInterface` (Task 2), `QueryBusInterface` + `FindPublishedCourseQuery` (Catalog, Faza 3).
- Produces:
  - `interface CourseAvailabilityChecker { public function isEnrollable(Uuid $courseId): bool; }`
  - `EnrollStudentCommand(Uuid $userId, Uuid $courseId)`
  - `EnrollStudentHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): sprawdza dostępność (port) → `CourseNotEnrollableException`; sprawdza brak duplikatu (repo) → `AlreadyEnrolledException`; tworzy `Enrollment`, zapisuje, publikuje `UserEnrolled` (po save).
  - `DoctrineEnrollmentRepository`, `CatalogCourseAvailabilityChecker`.

- [ ] **Step 1: Utwórz port `CourseAvailabilityChecker.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use Symfony\Component\Uid\Uuid;

interface CourseAvailabilityChecker
{
    public function isEnrollable(Uuid $courseId): bool;
}
```

- [ ] **Step 2: Utwórz `EnrollStudentCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Application\EnrollStudent;

use Symfony\Component\Uid\Uuid;

final readonly class EnrollStudentCommand
{
    public function __construct(
        public Uuid $userId,
        public Uuid $courseId,
    ) {
    }
}
```

- [ ] **Step 3: Napisz test `tests/Unit/Enrollment/Application/EnrollStudentHandlerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enrollment\Application;

use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentHandler;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\EventBusInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollStudentHandlerTest extends TestCase
{
    private function checker(bool $enrollable): CourseAvailabilityChecker
    {
        return new class($enrollable) implements CourseAvailabilityChecker {
            public function __construct(private bool $enrollable) {}
            public function isEnrollable(Uuid $courseId): bool { return $this->enrollable; }
        };
    }

    private function repo(bool $exists): EnrollmentRepository
    {
        return new class($exists) implements EnrollmentRepository {
            public array $saved = [];
            public function __construct(private bool $exists) {}
            public function save(Enrollment $enrollment): void { $this->saved[] = $enrollment; }
            public function exists(Uuid $userId, Uuid $courseId): bool { return $this->exists; }
        };
    }

    private function eventBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            public array $published = [];
            public function publish(object $event): void { $this->published[] = $event; }
        };
    }

    public function testEnrollsAndPublishesEvent(): void
    {
        $repo = $this->repo(false);
        $bus = $this->eventBus();
        $handler = new EnrollStudentHandler($this->checker(true), $repo, $bus);

        $userId = Uuid::v4();
        $courseId = Uuid::v4();
        $handler(new EnrollStudentCommand($userId, $courseId));

        self::assertCount(1, $repo->saved);
        self::assertCount(1, $bus->published);
        self::assertInstanceOf(UserEnrolled::class, $bus->published[0]);
        self::assertSame((string) $userId, $bus->published[0]->userId);
        self::assertSame((string) $courseId, $bus->published[0]->courseId);
    }

    public function testRejectsNonEnrollableCourse(): void
    {
        $handler = new EnrollStudentHandler($this->checker(false), $this->repo(false), $this->eventBus());

        $this->expectException(CourseNotEnrollableException::class);
        $handler(new EnrollStudentCommand(Uuid::v4(), Uuid::v4()));
    }

    public function testRejectsDuplicateEnrollment(): void
    {
        $handler = new EnrollStudentHandler($this->checker(true), $this->repo(true), $this->eventBus());

        $this->expectException(AlreadyEnrolledException::class);
        $handler(new EnrollStudentCommand(Uuid::v4(), Uuid::v4()));
    }
}
```

- [ ] **Step 4: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Enrollment/Application/EnrollStudentHandlerTest.php`
Expected: FAIL — handler nie istnieje.

- [ ] **Step 5: Utwórz `EnrollStudentHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Application\EnrollStudent;

use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class EnrollStudentHandler
{
    public function __construct(
        private CourseAvailabilityChecker $courses,
        private EnrollmentRepository $enrollments,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(EnrollStudentCommand $command): void
    {
        if (!$this->courses->isEnrollable($command->courseId)) {
            throw CourseNotEnrollableException::create();
        }
        if ($this->enrollments->exists($command->userId, $command->courseId)) {
            throw AlreadyEnrolledException::create();
        }

        $occurredAt = new \DateTimeImmutable();
        $this->enrollments->save(
            Enrollment::enroll(Uuid::v4(), $command->userId, $command->courseId, $occurredAt)
        );

        $this->eventBus->publish(new UserEnrolled(
            (string) $command->userId,
            (string) $command->courseId,
            $occurredAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
```

- [ ] **Step 6: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Enrollment/Application/EnrollStudentHandlerTest.php`
Expected: PASS (3 testy), pristine.

- [ ] **Step 7: Utwórz `DoctrineEnrollmentRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Doctrine;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEnrollmentRepository implements EnrollmentRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Enrollment $enrollment): void
    {
        $this->em->persist($enrollment);
        $this->em->flush();
    }

    public function exists(Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(Enrollment::class)
            ->count(['userId' => $userId, 'courseId' => $courseId]) > 0;
    }
}
```

- [ ] **Step 8: Utwórz adapter `CatalogCourseAvailabilityChecker.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Catalog;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogCourseAvailabilityChecker implements CourseAvailabilityChecker
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function isEnrollable(Uuid $courseId): bool
    {
        return $this->queryBus->ask(new FindPublishedCourseQuery($courseId)) instanceof Course;
    }
}
```

> Granica modułu: adapter w warstwie Infrastructure Enrollment integruje się z Catalog przez jego publiczne zapytanie (`FindPublishedCourseQuery`) — Domain/Application Enrollment nie zna Catalog.

- [ ] **Step 9: Zwiąż aliasy w `config/services.yaml`**

```yaml
    App\Enrollment\Domain\EnrollmentRepository:
        alias: App\Enrollment\Infrastructure\Doctrine\DoctrineEnrollmentRepository

    App\Enrollment\Application\CourseAvailabilityChecker:
        alias: App\Enrollment\Infrastructure\Catalog\CatalogCourseAvailabilityChecker
```

- [ ] **Step 10: Napisz test integracyjny `tests/Integration/Enrollment/EnrollStudentIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Enrollment;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EnrollStudentIntegrationTest extends KernelTestCase
{
    private function publishCourse(CommandBusInterface $bus): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testEnrollPersistsAndSendsEventToTransport(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);
        $userId = Uuid::v4();

        $bus->dispatch(new EnrollStudentCommand($userId, $courseId));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $event = $sent[0]->getMessage();
        self::assertInstanceOf(UserEnrolled::class, $event);
        self::assertSame((string) $userId, $event->userId);
        self::assertSame((string) $courseId, $event->courseId);
    }

    public function testCannotEnrollTwice(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);
        $userId = Uuid::v4();

        $bus->dispatch(new EnrollStudentCommand($userId, $courseId));

        $this->expectException(AlreadyEnrolledException::class);
        $bus->dispatch(new EnrollStudentCommand($userId, $courseId));
    }

    public function testCannotEnrollInUnpublishedCourse(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $draftId = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($draftId, Uuid::v4(), 'Roboczy', 'Opis'));

        $this->expectException(CourseNotEnrollableException::class);
        $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $draftId));
    }
}
```

- [ ] **Step 11: Uruchom test integracyjny + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Enrollment/EnrollStudentIntegrationTest.php`
Expected: PASS (3 testy), pristine. (`kafka_events` w testach = in-memory, więc bez realnej Kafki.)

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: cały zestaw zielony, pristine.

- [ ] **Step 12: Commit**

```bash
git add src/Enrollment/Application/ src/Enrollment/Infrastructure/ config/services.yaml tests/Unit/Enrollment/Application/ tests/Integration/Enrollment/
git commit -m "feat(enrollment): EnrollStudent (handler) + repo + adapter Catalog + publikacja UserEnrolled"
```

---

### Task 5: UI zapisu + smoke-test realnej Kafki

**Files:**
- Create: `src/Enrollment/UI/Controller/EnrollController.php`
- Modify: `config/routes.yaml` (routing Enrollment)
- Modify: `config/packages/security.yaml` (zapis wymaga zalogowania)
- Modify: `templates/catalog/detail.html.twig` (przycisk „Zapisz się")
- Create: `tests/Functional/Enrollment/EnrollControllerTest.php`
- Create: `tests/Smoke/Kafka/KafkaRoundtripTest.php`
- Modify: `phpunit.dist.xml` (wyłącz grupę `kafka` z domyślnego runu)

**Interfaces:**
- Consumes: `EnrollStudentCommand` + `CommandBusInterface`, wyjątki, `User` (Identity, przez getUser), `KafkaTransport` (smoke).
- Produces: trasa `enroll` (`POST /courses/{id}/enroll`).

- [ ] **Step 1: Utwórz `EnrollController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Enrollment\UI\Controller;

use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Identity\Domain\User;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class EnrollController extends AbstractController
{
    #[Route('/courses/{id}/enroll', name: 'enroll', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, CommandBusInterface $commandBus): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Oczekiwano zalogowanego użytkownika.');
        }

        try {
            $commandBus->dispatch(new EnrollStudentCommand($user->getId(), Uuid::fromString($id)));
            $this->addFlash('success', 'Zapisano na kurs.');
        } catch (AlreadyEnrolledException|CourseNotEnrollableException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('catalog_detail', ['id' => $id]);
    }
}
```

- [ ] **Step 2: Dodaj routing w `config/routes.yaml`**

```yaml
enrollment_controllers:
    resource:
        path: ../src/Enrollment/UI/Controller/
        namespace: App\Enrollment\UI\Controller
    type: attribute
```

- [ ] **Step 3: (Opcjonalnie) wzmocnij autoryzację w `config/packages/security.yaml`**

`#[IsGranted('ROLE_USER')]` na kontrolerze już chroni akcję. Nie dodawaj reguły `access_control` dla `/courses` (przeglądanie ma pozostać publiczne). Pomiń zmiany w security.yaml, jeśli `#[IsGranted]` wystarcza (zweryfikuj testem w Step 6).

- [ ] **Step 4: Dodaj przycisk w `templates/catalog/detail.html.twig`**

W bloku `body`, pod opisem kursu, dodaj:

```twig
    {% for label, messages in app.flashes %}
        {% for message in messages %}<div class="alert {{ label }}">{{ message }}</div>{% endfor %}
    {% endfor %}

    {% if is_granted('ROLE_USER') %}
        <form method="post" action="{{ path('enroll', {id: course.id}) }}">
            <button type="submit">Zapisz się</button>
        </form>
    {% else %}
        <p><a href="{{ path('login') }}">Zaloguj się, aby zapisać się na kurs</a></p>
    {% endif %}
```

- [ ] **Step 5: Napisz test funkcjonalny `tests/Functional/Enrollment/EnrollControllerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Enrollment;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EnrollControllerTest extends WebTestCase
{
    private function publishCourse(CommandBusInterface $bus): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testAnonymousCannotEnroll(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);

        $client->request('POST', '/courses/'.$courseId.'/enroll');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testStudentCanEnrollAndEventIsSent(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);

        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        $client->loginUser($user);

        $client->request('POST', '/courses/'.$courseId.'/enroll');
        self::assertResponseRedirects('/courses/'.$courseId);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        self::assertCount(1, $transport->getSent());
    }
}
```

- [ ] **Step 6: Uruchom test funkcjonalny**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Enrollment/EnrollControllerTest.php`
Expected: PASS (2 testy), pristine. Jeśli anonim dostaje 403 zamiast 302→/login, sprawdź konfigurację firewalla (entry_point/login) — `#[IsGranted]` na firewallu z form_login powinno przekierować anonima na login.

- [ ] **Step 7: Wyłącz grupę `kafka` z domyślnego runu w `phpunit.dist.xml`**

W sekcji `<phpunit>` dodaj (jeśli brak) blok wykluczający grupę:

```xml
    <groups>
        <exclude>
            <group>kafka</group>
        </exclude>
    </groups>
```

- [ ] **Step 8: Napisz smoke-test realnej Kafki `tests/Smoke/Kafka/KafkaRoundtripTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Kafka;

use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransportFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[Group('kafka')]
final class KafkaRoundtripTest extends TestCase
{
    public function testSendAndConsumeRoundtrip(): void
    {
        $factory = new KafkaTransportFactory();
        $serializer = new PhpSerializer();
        $topic = 'smoke.test.'.bin2hex(random_bytes(4));

        $transport = $factory->createTransport('kafka://kafka:9092', [
            'topic' => $topic,
            'consumer_group' => 'smoke-'.bin2hex(random_bytes(4)),
            'auto_offset_reset' => 'earliest',
            'consume_timeout_ms' => 10000,
        ], $serializer);

        $transport->send(new Envelope(new \stdClass()));

        $received = null;
        for ($i = 0; $i < 5 && null === $received; ++$i) {
            foreach ($transport->get() as $envelope) {
                $received = $envelope;
                $transport->ack($envelope);
            }
        }

        self::assertNotNull($received, 'Nie odebrano wiadomości z realnej Kafki.');
    }
}
```

- [ ] **Step 9: Uruchom smoke-test przeciw realnej Kafce**

Run: `docker compose up -d kafka && docker compose run --rm php vendor/bin/phpunit --group kafka`
Expected: PASS (1 test) — wiadomość przeszła round-trip przez `kafka:9092`. (Jeśli timeout, zwiększ `consume_timeout_ms`/liczbę prób; broker musi być `healthy`.)

- [ ] **Step 10: Zweryfikuj end-to-end ręcznie (Kafka UI)**

Run: `docker compose up -d` (cały stack)
- Zaloguj się jako student (utwórz przez rejestrację), wejdź na opublikowany kurs, kliknij „Zapisz się".
- W Kafka UI (http://localhost:8081) sprawdź topic `enrollment.events` — powinna pojawić się wiadomość `UserEnrolled` (JSON z `userId`/`courseId`/`occurredAt`).

> To weryfikacja manualna (nie test). Potwierdza realną produkcję na Kafkę przez `kafka_events`.

- [ ] **Step 11: Pełny zestaw (bez grupy kafka) — regresja**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: cały zestaw zielony, pristine, grupa `kafka` pominięta.

- [ ] **Step 12: Commit**

```bash
git add src/Enrollment/UI/ config/routes.yaml templates/catalog/detail.html.twig tests/Functional/Enrollment/ tests/Smoke/ phpunit.dist.xml
git commit -m "feat(enrollment): UI zapisu na kurs + smoke-test realnej Kafki (round-trip)"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu:**
- Zapis studenta na kurs → Tasks 3-5 ✅
- Publikacja `UserEnrolled` na Kafkę (topic `enrollment.events`) → Tasks 1,2,4 ✅
- Przepływ A ze specu (§3): command → handler → save → publish event → Kafka → (konsumenci w Fazie 5) ✅ (producent)
- Idempotencja/integralność: unikat (userId, courseId) + `AlreadyEnrolledException` ✅
- Granica modułów: `userId`/`courseId` jako Uuid; Catalog tylko przez port+adapter ✅
- Transport Kafka (php-rdkafka) z widocznymi consumer groups/offsetami → Task 1 (group.id, manualny commit) ✅; testy na in-memory → Task 2/4 ✅; jeden smoke-test realnej Kafki → Task 5 ✅
- Publikacja po commicie (bez outboxa) → Task 4 (publish po save) ✅

**Placeholdery:** brak; migracje generowane (`migrations:diff`) — celowe, z opisem oczekiwanego SQL.

**Spójność typów:** `EnrollmentRepository` (`save`, `exists(Uuid,Uuid)`) spójne (Task 3 def, Task 4 użycie). `CourseAvailabilityChecker::isEnrollable(Uuid)` spójne (Task 4). `EventBusInterface::publish(object)` (Task 2) używane w handlerze (Task 4). `UserEnrolled(string,string,string)` spójne (Task 3 def, Task 4 publish, testy). `EnrollStudentCommand(Uuid,Uuid)` spójne (Task 4, Task 5 UI). Transport: `KafkaTransportFactory`/`KafkaTransport`/`KafkaMessageStamp` (Task 1) używane w messenger.yaml routing (Task 2).

**Świadome decyzje:**
- Własny transport Kafka (decyzja użytkownika) — bez zależności od niedojrzałych paczek 0.x/dev-main; pełna kontrola (consumer groups, offsety, manualny commit = at-least-once).
- `UserEnrolled` z polami `string` + serializer JSON → czytelna wiadomość w Kafka UI (showcase).
- `event.bus` z `allow_no_handlers: true` (producent bez lokalnego handlera; konsumenci w Fazie 5).
- Testy na `in-memory` (override DSN w `.env.test`), smoke realnej Kafki w grupie `kafka` wyłączonej z domyślnego runu.
- Sprawdzenie dostępności kursu przez `FindPublishedCourseQuery` (Catalog) — reużycie istniejącego zapytania, brak duplikacji reguły „published".

**Uwagi wykonawcze:**
- `producev` z nagłówkami wymaga librdkafka ≥ 0.11 (obraz alpine to spełnia); jeśli `producev` niedostępne, użyć `produce()` bez nagłówków (nagłówki Messengera można wtedy wstrzyknąć do body przez serializer — ale producev powinno działać).
- W testach `messenger.transport.kafka_events` to `InMemoryTransport` (dzięki `.env.test`); pobranie z kontenera testowego działa (transporty dostępne w test container).
- Smoke-test wymaga zdrowego brokera; przy fladze `--group kafka`. Domyślny `phpunit` go pomija.
- Jeśli anonimowy POST /enroll daje 403 zamiast redirectu na login, zweryfikować `entry_point`/`form_login` firewalla (Faza 2) — ewentualnie dodać jawny `entry_point`.
```
