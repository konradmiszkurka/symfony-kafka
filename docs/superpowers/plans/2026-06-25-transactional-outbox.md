# Transactional Outbox — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publikować integration eventy na Kafkę atomowo ze zmianą stanu domeny — przez transactional outbox po stronie producenta (zapis eventu do tabeli `outbox` w tej samej transakcji co domena, osobny worker-relay dowozi na Kafkę).

**Architecture:** `command.bus` owija handlery w transakcję (`doctrine_transaction`). Handlery publikują przez `EventBusInterface`, którego nową implementacją jest `OutboxEventBus` — persistuje wiersz `outbox` zamiast słać na Kafkę. Worker `app:outbox:relay` czyta niewysłane wiersze, dispatchuje event na surowy `event.bus` (routing → Kafka), oznacza `sentAt`. Konsumenci są idempotentni → relay at-least-once jest bezpieczny.

**Tech Stack:** Symfony 7.4 Messenger (+ `doctrine_transaction` middleware), Doctrine ORM + migrations, Symfony Serializer (JSON), symfony/uid, PHPUnit, DAMA.

## Global Constraints

- PHP **8.4**, Symfony **7.x**; baza **MySQL 8.4**. Komendy w kontenerze: `docker compose run --rm php <cmd>`; DB: `docker compose up -d mysql` najpierw.
- Komponenty outboxa w module **Shared**: `App\Shared\Infrastructure\Outbox\*`, `App\Shared\UI\Command\*`.
- **Domain/Application bez frameworka** — outbox to Infrastructure/UI (może używać Doctrine/Symfony).
- Zakres: producent wszystkich 3 eventów (`UserEnrolled`, `LessonCompleted`, `CourseCompleted`). Konsument-side `send→save` poza zakresem.
- Relay używa **surowego `event.bus`** (`MessageBusInterface`), handlery **`EventBusInterface`** (`OutboxEventBus`) — inaczej pętla.
- `OutboxEventBus.publish` persistuje BEZ `flush` (commit robi middleware transakcyjne).
- Testy pristine (0 deprecations/warningów/PHPUnit notices); izolacja DB (DAMA). Mocki bez oczekiwań → `createStub`/anonimowe fake.
- **Commity bez śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code"). Git user: `konrad`. Po merge: push na origin.
- Każdy task kończy się commitem.

---

## Struktura plików

```
src/Shared/Infrastructure/Outbox/
  OutboxMessage.php            # encja Doctrine (tabela outbox)
  OutboxRepository.php         # port (add/save/unsent)
  DoctrineOutboxRepository.php # implementacja
  OutboxRelay.php              # serwis relay
src/Shared/Infrastructure/Bus/
  OutboxEventBus.php           # EventBusInterface -> persist do outbox
src/Shared/UI/Command/
  OutboxRelayCommand.php       # app:outbox:relay (worker)
config/packages/messenger.yaml # command.bus + doctrine_transaction
config/packages/doctrine.yaml  # mapowanie Outbox (+ ewentualnie use_savepoints)
config/services.yaml           # alias EventBusInterface -> OutboxEventBus; OutboxRelay $eventBus
migrations/Version*.php         # tabela outbox
README.md                      # uruchomienie relay
tests/Unit/Shared/Outbox/...  tests/Integration/Shared/Outbox/...
# AKTUALIZACJA (ripple):
tests/Integration/Enrollment/EnrollStudentIntegrationTest.php
tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php
tests/Functional/Enrollment/EnrollControllerTest.php
```

---

### Task 1: OutboxMessage (encja) + OutboxRepository + mapowanie + migracja

**Files:**
- Create: `src/Shared/Infrastructure/Outbox/OutboxMessage.php`
- Create: `src/Shared/Infrastructure/Outbox/OutboxRepository.php`
- Create: `src/Shared/Infrastructure/Outbox/DoctrineOutboxRepository.php`
- Modify: `config/packages/doctrine.yaml` (mapowanie Outbox)
- Modify: `config/services.yaml` (alias OutboxRepository)
- Create: `tests/Unit/Shared/Outbox/OutboxMessageTest.php`
- Create: `migrations/Version*.php`

**Interfaces:**
- Produces:
  - `OutboxMessage`: `static create(string $messageType, string $payload): self`; `markSent(\DateTimeImmutable $at): void`; `getId(): Uuid`; `getMessageType(): string`; `getPayload(): string`; `getSentAt(): ?\DateTimeImmutable`.
  - `interface OutboxRepository { public function add(OutboxMessage $m): void; public function save(OutboxMessage $m): void; /** @return list<OutboxMessage> */ public function unsent(int $limit): array; }`
  - `DoctrineOutboxRepository implements OutboxRepository`.

- [ ] **Step 1: Dodaj mapowanie w `config/packages/doctrine.yaml`**

W `doctrine.orm.mappings` dopisz:

```yaml
            Outbox:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Shared/Infrastructure/Outbox'
                prefix: 'App\Shared\Infrastructure\Outbox'
                alias: Outbox
```

> `dir` zawiera też repo i relay (nie-encje) — Doctrine ignoruje klasy bez `#[ORM\Entity]`.

- [ ] **Step 2: Utwórz `src/Shared/Infrastructure/Outbox/OutboxMessage.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox')]
#[ORM\Index(name: 'idx_outbox_sent_at', columns: ['sent_at'])]
final class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $messageType;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt;

    private function __construct(Uuid $id, string $messageType, string $payload, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->messageType = $messageType;
        $this->payload = $payload;
        $this->createdAt = $createdAt;
        $this->sentAt = null;
    }

    public static function create(string $messageType, string $payload): self
    {
        return new self(Uuid::v4(), $messageType, $payload, new \DateTimeImmutable());
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
}
```

- [ ] **Step 3: Utwórz port `src/Shared/Infrastructure/Outbox/OutboxRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxRepository
{
    /** Persist bez flush — commit robi transakcja owijająca handler. */
    public function add(OutboxMessage $message): void;

    /** Persist + flush — używane przez relay przy oznaczaniu jako wysłane. */
    public function save(OutboxMessage $message): void;

    /** @return list<OutboxMessage> */
    public function unsent(int $limit): array;
}
```

- [ ] **Step 4: Utwórz `src/Shared/Infrastructure/Outbox/DoctrineOutboxRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineOutboxRepository implements OutboxRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(OutboxMessage $message): void
    {
        $this->em->persist($message);
    }

    public function save(OutboxMessage $message): void
    {
        $this->em->persist($message);
        $this->em->flush();
    }

    public function unsent(int $limit): array
    {
        return array_values(
            $this->em->getRepository(OutboxMessage::class)
                ->findBy(['sentAt' => null], ['createdAt' => 'ASC'], $limit)
        );
    }
}
```

- [ ] **Step 5: Alias w `config/services.yaml`**

```yaml
    App\Shared\Infrastructure\Outbox\OutboxRepository:
        alias: App\Shared\Infrastructure\Outbox\DoctrineOutboxRepository
```

- [ ] **Step 6: Napisz test `tests/Unit/Shared/Outbox/OutboxMessageTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OutboxMessageTest extends TestCase
{
    public function testCreateIsUnsent(): void
    {
        $m = OutboxMessage::create('App\\Some\\Event', '{"a":1}');

        self::assertInstanceOf(Uuid::class, $m->getId());
        self::assertSame('App\\Some\\Event', $m->getMessageType());
        self::assertSame('{"a":1}', $m->getPayload());
        self::assertNull($m->getSentAt());
    }

    public function testMarkSent(): void
    {
        $m = OutboxMessage::create('App\\Some\\Event', '{}');
        $at = new \DateTimeImmutable('2026-06-25T10:00:00+00:00');

        $m->markSent($at);

        self::assertSame($at, $m->getSentAt());
    }
}
```

- [ ] **Step 7: Uruchom test + walidacja mapowania**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Shared/Outbox/OutboxMessageTest.php`
Expected: PASS (2 testy), pristine.

Run: `docker compose up -d mysql && docker compose run --rm php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Migracja (tabela outbox)**

Run: `docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction`
Expected: `migrations/Version*.php` z `CREATE TABLE outbox` (id BINARY(16), message_type VARCHAR(255), payload LONGTEXT, created_at DATETIME, sent_at DATETIME nullable, indeks na sent_at). Uzupełnij `getDescription()`.

Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction`
Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction --env=test`
Expected: zastosowane (dev + test).

- [ ] **Step 9: Commit**

```bash
git add src/Shared/Infrastructure/Outbox/ config/packages/doctrine.yaml config/services.yaml tests/Unit/Shared/Outbox/ migrations/
git commit -m "feat(outbox): encja OutboxMessage + OutboxRepository + mapowanie i migracja"
```

---

### Task 2: Cutover na outbox — doctrine_transaction + OutboxEventBus + przepięcie + ripple

**Files:**
- Create: `src/Shared/Infrastructure/Bus/OutboxEventBus.php`
- Modify: `config/packages/messenger.yaml` (command.bus + doctrine_transaction)
- Modify: `config/services.yaml` (alias EventBusInterface -> OutboxEventBus)
- Create: `tests/Integration/Shared/Outbox/OutboxPublishingTest.php`
- Modify: `tests/Integration/Enrollment/EnrollStudentIntegrationTest.php`
- Modify: `tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php`
- Modify: `tests/Functional/Enrollment/EnrollControllerTest.php`
- (warunkowo) Modify: `config/packages/doctrine.yaml` (`use_savepoints` — patrz Step 7)

**Interfaces:**
- Consumes: `OutboxMessage`, `OutboxRepository` (Task 1); `EventBusInterface` (Shared, istniejący).
- Produces: `OutboxEventBus implements EventBusInterface` — `publish(object $event): void` persistuje `OutboxMessage` (przez `OutboxRepository::add`, bez flush). Po tym tasku `EventBusInterface` to `OutboxEventBus`; eventy idą do tabeli `outbox`, NIE wprost na Kafkę.

- [ ] **Step 1: Utwórz `src/Shared/Infrastructure/Bus/OutboxEventBus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\EventBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class OutboxEventBus implements EventBusInterface
{
    public function __construct(
        private OutboxRepository $outbox,
        private SerializerInterface $serializer,
    ) {
    }

    public function publish(object $event): void
    {
        $this->outbox->add(
            OutboxMessage::create($event::class, $this->serializer->serialize($event, 'json'))
        );
    }
}
```

- [ ] **Step 2: Dodaj `doctrine_transaction` do `command.bus` w `config/packages/messenger.yaml`**

Zmień middleware busa `command.bus` na:

```yaml
            command.bus:
                middleware:
                    - validation
                    - doctrine_transaction
```

(Pozostałe busy bez zmian.)

- [ ] **Step 3: Przepnij `EventBusInterface` na `OutboxEventBus` w `config/services.yaml`**

Znajdź istniejący alias `App\Shared\Application\Bus\EventBusInterface: alias: ...MessengerEventBus` i zmień cel:

```yaml
    App\Shared\Application\Bus\EventBusInterface:
        alias: App\Shared\Infrastructure\Bus\OutboxEventBus
```

(`MessengerEventBus` zostaje w kodzie — relay i tak używa surowego `event.bus`; usunięcie pliku poza zakresem.)

- [ ] **Step 4: Napisz test atomowości `tests/Integration/Shared/Outbox/OutboxPublishingTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Outbox;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class OutboxPublishingTest extends KernelTestCase
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

    public function testEventGoesToOutboxNotDirectlyToKafka(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);
        $userId = Uuid::v4();

        $bus->dispatch(new EnrollStudentCommand($userId, $courseId));

        // Event jest w outboxie...
        $unsent = self::getContainer()->get(OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(UserEnrolled::class, $types);

        // ...ale NIE poszedł jeszcze wprost na Kafkę (transport in-memory pusty dla eventów).
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        self::assertCount(0, $transport->getSent());
    }

    public function testFailedCommandLeavesNoOutboxRow(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $outbox = self::getContainer()->get(OutboxRepository::class);
        $before = \count($outbox->unsent(1000));

        try {
            // kurs nieopublikowany -> handler rzuca przed zapisem
            $draft = Uuid::v4();
            $bus->dispatch(new CreateCourseCommand($draft, Uuid::v4(), 'Roboczy', 'Opis'));
            $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $draft));
            self::fail('Spodziewano się wyjątku.');
        } catch (CourseNotEnrollableException) {
            // oczekiwane
        }

        self::assertCount($before, $outbox->unsent(1000));
    }
}
```

- [ ] **Step 5: Uruchom nowy test — najpierw FAIL (przed Step 1-3), potem PASS**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Shared/Outbox/OutboxPublishingTest.php`
Expected po Step 1-3: PASS (2 testy), pristine.

> `OutboxRepository` jest pobieralne z kontenera testowego, bo jest wstrzykiwane do `OutboxEventBus` (więc alias przeżywa kompilację).

- [ ] **Step 6: Zaktualizuj testy „ripple" (event idzie teraz do outboxa, nie wprost na Kafkę)**

W `tests/Integration/Enrollment/EnrollStudentIntegrationTest.php` — w teście `testEnrollPersistsAndSendsEventToTransport`: zastąp asercję na transporcie `kafka_events` asercją na outboxie. Zamień blok pobierający `messenger.transport.kafka_events` + `getSent()` na:

```php
        $unsent = self::getContainer()->get(\App\Shared\Infrastructure\Outbox\OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(\App\Enrollment\Domain\Event\UserEnrolled::class, $types);
```

(Asercja persystencji enrollmentu — `exists(...)` — zostaje bez zmian.)

W `tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php` — w `testCompletingAllLessonsEmitsCourseCompleted`: zamień asercje na `messenger.transport.kafka_progress`/`getSent()` na asercję na outboxie:

```php
        $unsent = self::getContainer()->get(\App\Shared\Infrastructure\Outbox\OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(\App\Progress\Domain\Event\LessonCompleted::class, $types);
        self::assertContains(\App\Progress\Domain\Event\CourseCompleted::class, $types);
```

W `tests/Functional/Enrollment/EnrollControllerTest.php` — w `testStudentCanEnrollAndEventIsSent`: zastąp asercję `messenger.transport.kafka_events` `getSent()` count 1 asercją na outboxie:

```php
        $unsent = self::getContainer()->get(\App\Shared\Infrastructure\Outbox\OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(\App\Enrollment\Domain\Event\UserEnrolled::class, $types);
```

(Usuń import `InMemoryTransport`, jeśli nieużywany po zmianie — pristine.)

- [ ] **Step 7: Pełny zestaw — i ewentualny fix savepointów (DAMA + doctrine_transaction)**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: cały zestaw zielony, pristine.

> **Jeśli** testy DB padają z błędem zagnieżdżonej transakcji / „savepoint" (DAMA owija test w transakcję, `doctrine_transaction` otwiera kolejną): dodaj w `config/packages/doctrine.yaml` pod `doctrine.dbal` opcję `use_savepoints: true` i uruchom ponownie. Na DBAL 4 savepointy są automatyczne i ten krok jest zbędny — dodaj go TYLKO jeśli wystąpi błąd. Sprawdź wersję: `docker compose run --rm php composer show doctrine/dbal | grep versions`.

- [ ] **Step 8: Commit**

```bash
git add src/Shared/Infrastructure/Bus/OutboxEventBus.php config/packages/messenger.yaml config/services.yaml config/packages/doctrine.yaml tests/
git commit -m "feat(outbox): publikacja eventów przez outbox (doctrine_transaction + OutboxEventBus)"
```

---

### Task 3: OutboxRelay + komenda app:outbox:relay + dokumentacja

**Files:**
- Create: `src/Shared/Infrastructure/Outbox/OutboxRelay.php`
- Create: `src/Shared/UI/Command/OutboxRelayCommand.php`
- Modify: `config/services.yaml` (OutboxRelay $eventBus -> @event.bus)
- Modify: `README.md`
- Create: `tests/Unit/Shared/Outbox/OutboxRelayTest.php`
- Create: `tests/Integration/Shared/Outbox/OutboxRelayIntegrationTest.php`

**Interfaces:**
- Consumes: `OutboxRepository`, `OutboxMessage` (Task 1); `OutboxEventBus`/outbox zapełniony (Task 2); surowy `event.bus` (`MessageBusInterface`); `Symfony\Component\Serializer\SerializerInterface`.
- Produces: `OutboxRelay::relayBatch(int $limit = 100): int` (liczba zrelayowanych); komenda `app:outbox:relay` (`--limit`, `--once`).

- [ ] **Step 1: Utwórz `src/Shared/Infrastructure/Outbox/OutboxRelay.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class OutboxRelay
{
    public function __construct(
        private OutboxRepository $outbox,
        private MessageBusInterface $eventBus,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
    ) {
    }

    public function relayBatch(int $limit = 100): int
    {
        $relayed = 0;

        foreach ($this->outbox->unsent($limit) as $message) {
            try {
                $event = $this->serializer->deserialize($message->getPayload(), $message->getMessageType(), 'json');
                $this->eventBus->dispatch($event);
                $message->markSent(new \DateTimeImmutable());
                $this->outbox->save($message);
                ++$relayed;
            } catch (\Throwable $e) {
                $this->logger->error('Relay outboxa nie powiódł się dla wiadomości {id}', [
                    'id' => (string) $message->getId(),
                    'exception' => $e,
                ]);
            }
        }

        return $relayed;
    }
}
```

- [ ] **Step 2: Zwiąż `OutboxRelay` z surowym `event.bus` w `config/services.yaml`**

```yaml
    App\Shared\Infrastructure\Outbox\OutboxRelay:
        arguments:
            $eventBus: '@event.bus'
```

> Bez tego autowiring wstrzyknąłby domyślny bus (`command.bus`). Musi być `event.bus` (ma routing eventów → transporty Kafka).

- [ ] **Step 3: Napisz test jednostkowy `tests/Unit/Shared/Outbox/OutboxRelayTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class OutboxRelayTest extends TestCase
{
    public function testRelayDispatchesEventAndMarksSent(): void
    {
        $message = OutboxMessage::create(\stdClass::class, '{}');

        $repo = new class($message) implements OutboxRepository {
            public array $saved = [];
            public function __construct(private OutboxMessage $m) {}
            public function add(OutboxMessage $message): void {}
            public function save(OutboxMessage $message): void { $this->saved[] = $message; }
            public function unsent(int $limit): array { return null === $this->m->getSentAt() ? [$this->m] : []; }
        };

        $event = new \stdClass();
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($event);

        $bus = new class implements MessageBusInterface {
            public array $dispatched = [];
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;
                return new Envelope($message);
            }
        };

        $relay = new OutboxRelay($repo, $bus, $serializer, new NullLogger());

        $count = $relay->relayBatch(10);

        self::assertSame(1, $count);
        self::assertSame([$event], $bus->dispatched);
        self::assertNotNull($message->getSentAt());
        self::assertSame([$message], $repo->saved);
    }
}
```

- [ ] **Step 4: Uruchom test — FAIL przed Step 1, PASS po**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Shared/Outbox/OutboxRelayTest.php`
Expected: PASS (1 test), pristine.

- [ ] **Step 5: Utwórz komendę `src/Shared/UI/Command/OutboxRelayCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\UI\Command;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Publikuje niewysłane eventy z outboxa na Kafkę')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rozmiar partii', '100')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Jeden przebieg i wyjście (do cron/testów)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $once = (bool) $input->getOption('once');

        do {
            $relayed = $this->relay->relayBatch($limit);
            if ($relayed > 0) {
                $output->writeln(sprintf('Zrelayowano %d wiadomości.', $relayed));
            }
            if ($once) {
                break;
            }
            if (0 === $relayed) {
                sleep(1);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 6: Napisz test integracyjny end-to-end `tests/Integration/Shared/Outbox/OutboxRelayIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Outbox;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class OutboxRelayIntegrationTest extends KernelTestCase
{
    public function testRelayMovesOutboxEventToKafkaTransportAndMarksSent(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);

        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));
        $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $courseId));

        // przed relay: w outboxie, brak na transporcie
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        self::assertCount(0, $transport->getSent());

        $relayed = self::getContainer()->get(OutboxRelay::class)->relayBatch(100);

        self::assertGreaterThanOrEqual(1, $relayed);
        // po relay: event na transporcie Kafka (in-memory)
        $messages = array_map(static fn ($e) => $e->getMessage()::class, $transport->getSent());
        self::assertContains(UserEnrolled::class, $messages);
        // outbox oznaczony jako wysłany (brak niewysłanych UserEnrolled)
        $unsentTypes = array_map(static fn ($m) => $m->getMessageType(), self::getContainer()->get(OutboxRepository::class)->unsent(100));
        self::assertNotContains(UserEnrolled::class, $unsentTypes);
    }
}
```

- [ ] **Step 7: Uruchom test integracyjny + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Shared/Outbox/OutboxRelayIntegrationTest.php`
Expected: PASS (1 test), pristine.

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: cały zestaw zielony, pristine (grupa `kafka` pominięta).

- [ ] **Step 8: Dokumentacja w `README.md`**

W sekcji „Konsumenci Kafki" dopisz uruchamianie relay (producent outboxa → Kafka):

```markdown
### Relay outboxa (producent → Kafka)
Eventy domenowe trafiają najpierw do tabeli `outbox` (atomowo ze zmianą stanu). Relay dowozi je na Kafkę:
```bash
docker compose exec php bin/console app:outbox:relay -vv        # worker w pętli
docker compose exec php bin/console app:outbox:relay --once     # jeden przebieg (cron)
```
```

- [ ] **Step 9: Commit**

```bash
git add src/Shared/Infrastructure/Outbox/OutboxRelay.php src/Shared/UI/Command/OutboxRelayCommand.php config/services.yaml README.md tests/Unit/Shared/Outbox/OutboxRelayTest.php tests/Integration/Shared/Outbox/OutboxRelayIntegrationTest.php
git commit -m "feat(outbox): relay (app:outbox:relay) publikujący eventy z outboxa na Kafkę"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu:**
- `doctrine_transaction` na `command.bus`; atomowy commit domena+outbox → Task 2 ✅
- `OutboxMessage` + migracja `outbox`; `OutboxEventBus` jako `EventBusInterface` → Task 1 + 2 ✅
- `OutboxRelay` + komenda `app:outbox:relay` → Task 3 ✅
- Testy: atomowość (outbox zapisany, Kafka pusta przed relay), failed-command (brak wiersza), relay → Task 2 + 3 ✅
- Ripple (3 istniejące testy) → Task 2 Step 6 ✅
- README → Task 3 Step 8 ✅
- Wszystkie 3 eventy przez outbox → przez przepięcie `EventBusInterface` (Task 2), bo wszystkie handlery publikują przez ten port ✅

**Placeholdery:** brak; migracja generowana (`migrations:diff`) — celowe, z opisem oczekiwanego SQL. Krok savepointów warunkowy, ale konkretny (kiedy i co zrobić).

**Spójność typów:** `OutboxRepository` (`add`/`save`/`unsent(int): array`) spójne między Task 1 (def), Task 2 (OutboxEventBus.add, testy.unsent), Task 3 (relay.unsent/save). `OutboxMessage` (`create`/`markSent`/`getMessageType`/`getPayload`/`getSentAt`/`getId`) spójne wszędzie. `OutboxRelay::relayBatch(int): int` spójne (Task 3 def, komenda, testy). `OutboxEventBus` (Task 2) używa `OutboxRepository::add` + `Symfony\Component\Serializer\SerializerInterface` — ten sam serializer co relay (`deserialize`).

**Świadome decyzje:**
- `OutboxEventBus.publish` przez `OutboxRepository::add` (persist BEZ flush) — commit robi `doctrine_transaction`. Relay używa `save` (flush) przy `markSent`.
- Relay na surowym `event.bus` (`MessageBusInterface`), nie `EventBusInterface` — brak pętli; reużycie routingu → transporty Kafka.
- Test atomowości weryfikuje pozytyw (outbox zapisany, Kafka pusta) + failed-command (brak wiersza). Pełny rollback częściowego zapisu wymaga sztucznego seam'a w handlerze — pominięty (gwarancję daje sprawdzony middleware Symfony + test pozytywny).
- Savepointy: dodawane warunkowo (DBAL 3); na DBAL 4 automatyczne.

**Uwagi wykonawcze:**
- Po Task 2 (przed Task 3) eventy lądują w outboxie, ale nic ich nie dowozi na Kafkę — to przejściowy stan między taskami; testy każdego tasku są zielone, a pełny flow wraca po Task 3 (relay).
- `OutboxRepository` pobieralne w testach, bo wstrzykiwane do `OutboxEventBus`/`OutboxRelay` (alias przeżywa kompilację).
