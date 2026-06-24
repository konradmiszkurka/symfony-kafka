# Faza 5: Progress + Notification (konsumenci Kafki) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Domknąć MVP: konsument **Progress** reaguje na `UserEnrolled` (inicjuje postęp), student oznacza lekcje jako ukończone (% ukończenia, wykrycie ukończenia kursu → `CourseCompleted`); konsument **Notification** wysyła maile (powitalny po zapisie, gratulacje po ukończeniu) — wszystko event-driven przez Kafkę, z osobnymi consumer groups, idempotencją i dead-letter.

**Architecture:** Dwa nowe bounded contexty (Progress, Notification). Integration eventy konsumowane przez Messenger z osobnych transportów Kafka (różne `group.id`), handlery wiązane `#[AsMessageHandler(fromTransport: ...)]`. Progress publikuje `LessonCompleted`/`CourseCompleted` na topic `progress.events`; Notification konsumuje `CourseCompleted` (osobna grupa). Idempotencja: Progress unikat (user,course) + skip; Notification dedupe po (typ,user,course). Dead-letter: `failed` transport (doctrine). Maile przez Symfony Mailer → Mailpit.

**Tech Stack:** Symfony 7.4 Messenger (własny transport Kafka z Fazy 4), symfony/mailer, php-rdkafka, Doctrine ORM + migrations, symfony/uid, Twig, PHPUnit, DAMA.

## Global Constraints

- PHP **8.4**, Symfony **7.x**; baza **MySQL 8.4**; broker **kafka:9092**; SMTP **mailpit:1025**. Komendy w kontenerze: `docker compose run --rm php <cmd>`. Usługi: `docker compose up -d mysql kafka mailpit`.
- Namespace PSR-4: `App\Progress\{Domain,Application,Infrastructure,UI}`, `App\Notification\{...}`. **Domain/Application bez frameworka** (dozwolone: `#[ORM\...]`, `#[AsMessageHandler]`).
- Konsumpcja: osobne transporty Kafka per consumer group; handlery wiązane `fromTransport`. **Testy: handlery wołane bezpośrednio** (sync), wszystkie transporty Kafka → `in-memory` w `.env.test`. Realnej Kafki dotyka tylko smoke z Fazy 4.
- **Idempotencja** (Kafka at-least-once): Progress init skip-if-exists; oznaczenie lekcji set-based (ponowne = no-op, bez ponownego eventu); Notification dedupe (typ,user,course).
- Granice modułów: Progress/Notification trzymają `Uuid` (user/course/lesson), nie encje. Dostęp do Catalog/Identity tylko przez porty + adaptery w Infrastructure. Integration event `UserEnrolled` (z Enrollment) i `CourseCompleted` (z Progress) to publiczne kontrakty — konsumenci mogą je importować.
- Maile: Symfony Mailer; w testach `MAILER_DSN=null://null` + `MailerAssertionsTrait`.
- Testy pristine (0 deprecations/warningów/PHPUnit notices); izolacja DB (DAMA); mocki bez oczekiwań → `createStub`/fake.
- **Commity bez śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code"). Git user: `konrad`.
- Każdy task kończy się commitem.

---

## Struktura plików

```
src/Identity/Domain/UserRepository.php                 # + ofId(Uuid): ?User
src/Identity/Infrastructure/Doctrine/DoctrineUserRepository.php  # + ofId
src/Progress/
  Domain/{CourseProgress.php, ProgressRepository.php}
  Domain/Event/{LessonCompleted.php, CourseCompleted.php}
  Domain/Exception/{ProgressNotFoundException.php, LessonNotInCourseException.php}
  Application/CourseStructureProvider.php               # port (lessonIds)
  Application/InitProgress/InitProgressOnUserEnrolledHandler.php
  Application/MarkLessonCompleted/{MarkLessonCompletedCommand.php, MarkLessonCompletedHandler.php}
  Infrastructure/Doctrine/DoctrineProgressRepository.php
  Infrastructure/Catalog/CatalogCourseStructureProvider.php
  UI/Controller/{ProgressController.php}                # mark lesson + progress view
src/Notification/
  Domain/{SentNotification.php, NotificationType.php, SentNotificationRepository.php}
  Application/{Mailer.php, RecipientResolver.php}        # porty
  Application/SendWelcome/SendWelcomeOnUserEnrolledHandler.php
  Application/SendCongrats/SendCongratsOnCourseCompletedHandler.php
  Infrastructure/Doctrine/DoctrineSentNotificationRepository.php
  Infrastructure/Symfony/{SymfonyMailer.php}
  Infrastructure/Identity/IdentityRecipientResolver.php
config/packages/messenger.yaml      # consumer transports, progress producer, failed, routing
config/packages/doctrine.yaml       # mapowania Progress, Notification
config/services.yaml                # aliasy
config/routes.yaml                  # routing Progress UI
.env / .env.test                    # MAILER_DSN, kafka DSN (test in-memory)
migrations/                         # course_progress, sent_notifications
templates/progress/...              # widok postępu + przycisk
tests/Unit|Integration|Functional/{Progress,Notification}/...
```

---

### Task 1: Wiring — Mailer + topologia konsumentów Kafki + dead-letter

**Files:**
- Modify: `config/packages/messenger.yaml`, `.env`, `.env.test`, `config/packages/mailer.yaml` (recipe)
- (composer) `symfony/mailer`

**Interfaces:**
- Produces: transporty `kafka_progress` (producent progress.events), `progress_enrollment_in`/`notification_enrollment_in` (konsumenci enrollment.events, różne grupy), `notification_progress_in` (konsument progress.events); `failed` (dead-letter); routing LessonCompleted/CourseCompleted → kafka_progress. Mailer (SMTP mailpit; null w testach).

- [ ] **Step 1: Zainstaluj Mailer**

Run: `docker compose run --rm php composer require symfony/mailer --no-interaction`
Expected: sukces; `config/packages/mailer.yaml` dodany.

- [ ] **Step 2: Ustaw MAILER_DSN**

`.env` — dopisz: `MAILER_DSN=smtp://mailpit:1025`
`.env.test` — dopisz: `MAILER_DSN=null://null`

- [ ] **Step 3: Rozbuduj `config/packages/messenger.yaml`**

W `framework.messenger.transports` zostaw `kafka_events` i dodaj poniżej:

```yaml
            kafka_progress:
                dsn: '%env(MESSENGER_KAFKA_EVENTS_DSN)%'
                serializer: messenger.transport.symfony_serializer
                options:
                    topic: progress.events
                    consumer_group: '%env(APP_ENV)%-progress-producer'
            progress_enrollment_in:
                dsn: '%env(MESSENGER_KAFKA_EVENTS_DSN)%'
                serializer: messenger.transport.symfony_serializer
                options:
                    topic: enrollment.events
                    consumer_group: '%env(APP_ENV)%-progress'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
            notification_enrollment_in:
                dsn: '%env(MESSENGER_KAFKA_EVENTS_DSN)%'
                serializer: messenger.transport.symfony_serializer
                options:
                    topic: enrollment.events
                    consumer_group: '%env(APP_ENV)%-notification'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
            notification_progress_in:
                dsn: '%env(MESSENGER_KAFKA_EVENTS_DSN)%'
                serializer: messenger.transport.symfony_serializer
                options:
                    topic: progress.events
                    consumer_group: '%env(APP_ENV)%-notification-progress'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
            failed: 'doctrine://default?queue_name=failed'
```

Dodaj pod `transports` (na poziomie `messenger`): `failure_transport: failed`. W `routing` dopisz:

```yaml
            'App\Progress\Domain\Event\LessonCompleted': kafka_progress
            'App\Progress\Domain\Event\CourseCompleted': kafka_progress
```

> Wszystkie transporty Kafka używają tego samego DSN brokera (`MESSENGER_KAFKA_EVENTS_DSN`); różnią się `topic`/`consumer_group`. W `.env.test` ten DSN to `in-memory://`, więc każdy transport staje się osobnym `InMemoryTransport`. Routing dla LessonCompleted/CourseCompleted odnosi się do klas tworzonych w Tasku 2 — patrz uwaga niżej.

> **Uwaga (jak w Fazie 4):** Symfony 7 waliduje istnienie klasy w `routing` przy kompilacji. Dodaj te dwie linie routingu DOPIERO w Tasku 2 po utworzeniu klas eventów (tu zostaw je zakomentowane). W tym tasku skonfiguruj transporty + failure_transport; routing eventów Progress odkomentuje Task 2.

- [ ] **Step 4: Utwórz tabelę dead-letter (doctrine messenger) na dev**

Run: `docker compose up -d mysql && docker compose run --rm php bin/console messenger:setup-transports failed`
Expected: utworzona tabela `messenger_messages` (dla failed). (Świeże środowiska muszą uruchomić tę komendę — odnotuj w README przy okazji Taska 6.)

- [ ] **Step 5: Weryfikacja**

Run: `docker compose run --rm php bin/console lint:container`
Expected: `linted successfully`.

Run: `docker compose run --rm php bin/console debug:messenger`
Expected: widoczne nowe transporty; brak błędów.

- [ ] **Step 6: Commit**

```bash
git add config/ .env .env.test composer.json composer.lock symfony.lock
git commit -m "feat(infra): Mailer + transporty konsumentów Kafki (osobne grupy) + dead-letter"
```

---

### Task 2: Domena Progress — CourseProgress, eventy, repo, mapowanie, migracja

**Files:**
- Modify: `config/packages/doctrine.yaml` (mapowanie Progress), `config/packages/messenger.yaml` (odkomentuj routing eventów Progress)
- Create: `src/Progress/Domain/CourseProgress.php`, `ProgressRepository.php`, `Event/LessonCompleted.php`, `Event/CourseCompleted.php`, `Exception/ProgressNotFoundException.php`, `Exception/LessonNotInCourseException.php`
- Create: `tests/Unit/Progress/Domain/CourseProgressTest.php`
- Create: `migrations/Version*.php`

**Interfaces:**
- Produces:
  - `CourseProgress`: `static start(Uuid $id, Uuid $userId, Uuid $courseId, int $totalLessons): self`; `markLessonCompleted(Uuid $lessonId): bool` (zwraca true jeśli nowo dodano, false jeśli już było); `isCompleted(): bool`; `completionPercentage(): int`; gettery `getUserId/getCourseId/completedCount/getTotalLessons`.
  - `LessonCompleted(string $userId, string $courseId, string $lessonId, string $occurredAt)`, `CourseCompleted(string $userId, string $courseId, string $occurredAt)`.
  - `interface ProgressRepository { save(CourseProgress): void; ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress; exists(Uuid $userId, Uuid $courseId): bool; }`

- [ ] **Step 1: Mapowanie w `config/packages/doctrine.yaml`**

Dopisz:

```yaml
            Progress:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Progress/Domain'
                prefix: 'App\Progress\Domain'
                alias: Progress
```

- [ ] **Step 2: Eventy**

`src/Progress/Domain/Event/LessonCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain\Event;

final readonly class LessonCompleted
{
    public function __construct(
        public string $userId,
        public string $courseId,
        public string $lessonId,
        public string $occurredAt,
    ) {
    }
}
```

`src/Progress/Domain/Event/CourseCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain\Event;

final readonly class CourseCompleted
{
    public function __construct(
        public string $userId,
        public string $courseId,
        public string $occurredAt,
    ) {
    }
}
```

- [ ] **Step 3: Wyjątki**

`src/Progress/Domain/Exception/ProgressNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain\Exception;

final class ProgressNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('Brak rozpoczętego postępu dla tego kursu.');
    }
}
```

`src/Progress/Domain/Exception/LessonNotInCourseException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain\Exception;

final class LessonNotInCourseException extends \DomainException
{
    public static function create(): self
    {
        return new self('Lekcja nie należy do tego kursu.');
    }
}
```

- [ ] **Step 4: Port `ProgressRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain;

use Symfony\Component\Uid\Uuid;

interface ProgressRepository
{
    public function save(CourseProgress $progress): void;

    public function ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress;

    public function exists(Uuid $userId, Uuid $courseId): bool;
}
```

- [ ] **Step 5: Test `tests/Unit/Progress/Domain/CourseProgressTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Progress\Domain;

use App\Progress\Domain\CourseProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CourseProgressTest extends TestCase
{
    public function testMarkingLessonsTracksPercentageAndCompletion(): void
    {
        $progress = CourseProgress::start(Uuid::v4(), Uuid::v4(), Uuid::v4(), 2);
        self::assertSame(0, $progress->completionPercentage());
        self::assertFalse($progress->isCompleted());

        $l1 = Uuid::v4();
        self::assertTrue($progress->markLessonCompleted($l1));
        self::assertSame(50, $progress->completionPercentage());
        // idempotencja: ta sama lekcja nie liczy się drugi raz
        self::assertFalse($progress->markLessonCompleted($l1));
        self::assertSame(50, $progress->completionPercentage());

        self::assertTrue($progress->markLessonCompleted(Uuid::v4()));
        self::assertSame(100, $progress->completionPercentage());
        self::assertTrue($progress->isCompleted());
    }
}
```

- [ ] **Step 6: Uruchom test — FAIL**, potem implementuj.

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Progress/Domain/CourseProgressTest.php` → FAIL.

- [ ] **Step 7: Encja `src/Progress/Domain/CourseProgress.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'course_progress')]
#[ORM\UniqueConstraint(name: 'uniq_progress_user_course', columns: ['user_id', 'course_id'])]
class CourseProgress
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
    private int $totalLessons;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $completedLessonIds;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    private function __construct(Uuid $id, Uuid $userId, Uuid $courseId, int $totalLessons)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->totalLessons = $totalLessons;
        $this->completedLessonIds = [];
        $this->completedAt = null;
    }

    public static function start(Uuid $id, Uuid $userId, Uuid $courseId, int $totalLessons): self
    {
        return new self($id, $userId, $courseId, $totalLessons);
    }

    public function markLessonCompleted(Uuid $lessonId): bool
    {
        $key = (string) $lessonId;
        if (\in_array($key, $this->completedLessonIds, true)) {
            return false;
        }

        $this->completedLessonIds[] = $key;
        if ($this->isCompleted() && null === $this->completedAt) {
            $this->completedAt = new \DateTimeImmutable();
        }

        return true;
    }

    public function isCompleted(): bool
    {
        return $this->totalLessons > 0 && $this->completedCount() >= $this->totalLessons;
    }

    public function completionPercentage(): int
    {
        if (0 === $this->totalLessons) {
            return 0;
        }

        return (int) floor($this->completedCount() / $this->totalLessons * 100);
    }

    public function completedCount(): int
    {
        return \count($this->completedLessonIds);
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCourseId(): Uuid
    {
        return $this->courseId;
    }

    public function getTotalLessons(): int
    {
        return $this->totalLessons;
    }
}
```

- [ ] **Step 8: Test PASS + walidacja + odkomentuj routing**

Run test → PASS pristine.
Run: `docker compose run --rm php bin/console doctrine:schema:validate --skip-sync` → OK.
Odkomentuj w `config/packages/messenger.yaml` linie routingu `LessonCompleted`/`CourseCompleted` → `kafka_progress` (dodane jako komentarz w Tasku 1). Run: `docker compose run --rm php bin/console lint:container` → OK.

- [ ] **Step 9: Migracja**

Run: `docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction` → `course_progress` z unikatem (user_id, course_id). Uzupełnij `getDescription()`.
Run migrate na dev + `--env=test`.

- [ ] **Step 10: Commit**

```bash
git add src/Progress/Domain/ tests/Unit/Progress/ config/packages/doctrine.yaml config/packages/messenger.yaml migrations/
git commit -m "feat(progress): CourseProgress + eventy Lesson/CourseCompleted + mapowanie, migracja, routing"
```

---

### Task 3: Progress — inicjalizacja postępu z UserEnrolled (konsument, idempotentny)

**Files:**
- Create: `src/Progress/Application/CourseStructureProvider.php` (port)
- Create: `src/Progress/Application/InitProgress/InitProgressOnUserEnrolledHandler.php`
- Create: `src/Progress/Infrastructure/Doctrine/DoctrineProgressRepository.php`
- Create: `src/Progress/Infrastructure/Catalog/CatalogCourseStructureProvider.php`
- Modify: `config/services.yaml`
- Create: `tests/Integration/Progress/InitProgressIntegrationTest.php`

**Interfaces:**
- Consumes: `UserEnrolled` (Enrollment), `CourseProgress`/`ProgressRepository` (Task 2), Catalog `FindPublishedCourseQuery` + `QueryBusInterface`.
- Produces:
  - `interface CourseStructureProvider { /** @return list<string> */ public function lessonIds(Uuid $courseId): array; }`
  - `InitProgressOnUserEnrolledHandler` (`#[AsMessageHandler(fromTransport: 'progress_enrollment_in')]`, `__invoke(UserEnrolled): void`) — idempotentny (skip jeśli postęp istnieje).

- [ ] **Step 1: Port `CourseStructureProvider.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Application;

use Symfony\Component\Uid\Uuid;

interface CourseStructureProvider
{
    /** @return list<string> identyfikatory lekcji kursu */
    public function lessonIds(Uuid $courseId): array;
}
```

- [ ] **Step 2: Adapter `CatalogCourseStructureProvider.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Infrastructure\Catalog;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Progress\Application\CourseStructureProvider;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogCourseStructureProvider implements CourseStructureProvider
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function lessonIds(Uuid $courseId): array
    {
        $course = $this->queryBus->ask(new FindPublishedCourseQuery($courseId));
        if (!$course instanceof Course) {
            return [];
        }

        $ids = [];
        foreach ($course->getSections() as $section) {
            foreach ($section->getLessons() as $lesson) {
                $ids[] = (string) $lesson->getId();
            }
        }

        return $ids;
    }
}
```

- [ ] **Step 3: Repo `DoctrineProgressRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Infrastructure\Doctrine;

use App\Progress\Domain\CourseProgress;
use App\Progress\Domain\ProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProgressRepository implements ProgressRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(CourseProgress $progress): void
    {
        $this->em->persist($progress);
        $this->em->flush();
    }

    public function ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress
    {
        return $this->em->getRepository(CourseProgress::class)
            ->findOneBy(['userId' => $userId, 'courseId' => $courseId]);
    }

    public function exists(Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(CourseProgress::class)
            ->count(['userId' => $userId, 'courseId' => $courseId]) > 0;
    }
}
```

- [ ] **Step 4: Handler `InitProgressOnUserEnrolledHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Application\InitProgress;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Progress\Application\CourseStructureProvider;
use App\Progress\Domain\CourseProgress;
use App\Progress\Domain\ProgressRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'progress_enrollment_in')]
final readonly class InitProgressOnUserEnrolledHandler
{
    public function __construct(
        private ProgressRepository $progress,
        private CourseStructureProvider $courses,
    ) {
    }

    public function __invoke(UserEnrolled $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->progress->exists($userId, $courseId)) {
            return; // idempotentnie — postęp już zainicjowany
        }

        $totalLessons = \count($this->courses->lessonIds($courseId));
        $this->progress->save(CourseProgress::start(Uuid::v4(), $userId, $courseId, $totalLessons));
    }
}
```

- [ ] **Step 5: Aliasy w `config/services.yaml`**

```yaml
    App\Progress\Domain\ProgressRepository:
        alias: App\Progress\Infrastructure\Doctrine\DoctrineProgressRepository

    App\Progress\Application\CourseStructureProvider:
        alias: App\Progress\Infrastructure\Catalog\CatalogCourseStructureProvider
```

- [ ] **Step 6: Test integracyjny `tests/Integration/Progress/InitProgressIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Progress;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Progress\Application\InitProgress\InitProgressOnUserEnrolledHandler;
use App\Progress\Domain\ProgressRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InitProgressIntegrationTest extends KernelTestCase
{
    public function testInitCreatesProgressWithLessonCountAndIsIdempotent(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L1', 't'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L2', 't'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $handler = self::getContainer()->get(InitProgressOnUserEnrolledHandler::class);
        $userId = Uuid::v4();
        $event = new UserEnrolled((string) $userId, (string) $courseId, '2026-06-24T10:00:00+00:00');

        $handler($event);
        $handler($event); // idempotentnie

        $repo = self::getContainer()->get(ProgressRepository::class);
        $progress = $repo->ofUserAndCourse($userId, $courseId);
        self::assertNotNull($progress);
        self::assertSame(2, $progress->getTotalLessons());
    }
}
```

> Handler i repo muszą być pobieralne z kontenera testowego — handler jest zarejestrowany jako usługa (autowiring), pobranie po klasie działa w test container.

- [ ] **Step 7: Uruchom (FAIL→GREEN) + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Progress/InitProgressIntegrationTest.php` → PASS (1 test), pristine.
Run: `docker compose run --rm php vendor/bin/phpunit` → cały zestaw zielony.

- [ ] **Step 8: Commit**

```bash
git add src/Progress/Application/ src/Progress/Infrastructure/ config/services.yaml tests/Integration/Progress/
git commit -m "feat(progress): inicjalizacja postępu z UserEnrolled (konsument, idempotentny) + adapter Catalog"
```

---

### Task 4: Progress — oznaczanie lekcji ukończonej (command + eventy)

**Files:**
- Create: `src/Progress/Application/MarkLessonCompleted/{MarkLessonCompletedCommand.php, MarkLessonCompletedHandler.php}`
- Create: `tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php`

**Interfaces:**
- Consumes: `ProgressRepository`, `CourseStructureProvider`, `EventBusInterface`, eventy Progress, wyjątki.
- Produces: `MarkLessonCompletedCommand(Uuid $userId, Uuid $courseId, Uuid $lessonId)`; `MarkLessonCompletedHandler` (`#[AsMessageHandler(bus: 'command.bus')]`): waliduje lekcję ∈ kurs, oznacza, publikuje `LessonCompleted` (jeśli nowo), `CourseCompleted` (jeśli właśnie ukończono).

- [ ] **Step 1: Command**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Application\MarkLessonCompleted;

use Symfony\Component\Uid\Uuid;

final readonly class MarkLessonCompletedCommand
{
    public function __construct(
        public Uuid $userId,
        public Uuid $courseId,
        public Uuid $lessonId,
    ) {
    }
}
```

- [ ] **Step 2: Handler**

```php
<?php

declare(strict_types=1);

namespace App\Progress\Application\MarkLessonCompleted;

use App\Progress\Application\CourseStructureProvider;
use App\Progress\Domain\Event\CourseCompleted;
use App\Progress\Domain\Event\LessonCompleted;
use App\Progress\Domain\Exception\LessonNotInCourseException;
use App\Progress\Domain\Exception\ProgressNotFoundException;
use App\Progress\Domain\ProgressRepository;
use App\Shared\Application\Bus\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkLessonCompletedHandler
{
    public function __construct(
        private ProgressRepository $progress,
        private CourseStructureProvider $courses,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(MarkLessonCompletedCommand $command): void
    {
        $progress = $this->progress->ofUserAndCourse($command->userId, $command->courseId);
        if (null === $progress) {
            throw ProgressNotFoundException::create();
        }
        if (!\in_array((string) $command->lessonId, $this->courses->lessonIds($command->courseId), true)) {
            throw LessonNotInCourseException::create();
        }

        $newlyCompleted = $progress->markLessonCompleted($command->lessonId);
        $this->progress->save($progress);

        if (!$newlyCompleted) {
            return; // idempotentnie — lekcja już była ukończona, brak eventów
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->eventBus->publish(new LessonCompleted(
            (string) $command->userId, (string) $command->courseId, (string) $command->lessonId, $now
        ));

        if ($progress->isCompleted()) {
            $this->eventBus->publish(new CourseCompleted(
                (string) $command->userId, (string) $command->courseId, $now
            ));
        }
    }
}
```

- [ ] **Step 3: Test integracyjny `tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Progress;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Domain\Course;
use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Progress\Application\InitProgress\InitProgressOnUserEnrolledHandler;
use App\Progress\Application\MarkLessonCompleted\MarkLessonCompletedCommand;
use App\Progress\Domain\Event\CourseCompleted;
use App\Progress\Domain\Event\LessonCompleted;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class MarkLessonCompletedIntegrationTest extends KernelTestCase
{
    public function testCompletingAllLessonsEmitsCourseCompleted(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L1', 't'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $userId = Uuid::v4();
        (self::getContainer()->get(InitProgressOnUserEnrolledHandler::class))(
            new UserEnrolled((string) $userId, (string) $courseId, '2026-06-24T10:00:00+00:00')
        );

        // jedyna lekcja kursu
        $course = self::getContainer()->get(QueryBusInterface::class)->ask(new FindPublishedCourseQuery($courseId));
        \assert($course instanceof Course);
        $lessonId = Uuid::fromString((string) $course->getSections()[0]->getLessons()[0]->getId());

        $bus->dispatch(new MarkLessonCompletedCommand($userId, $courseId, $lessonId));

        /** @var InMemoryTransport $progressTransport */
        $progressTransport = self::getContainer()->get('messenger.transport.kafka_progress');
        $messages = array_map(static fn ($e) => $e->getMessage(), $progressTransport->getSent());
        $types = array_map('get_class', $messages);
        self::assertContains(LessonCompleted::class, $types);
        self::assertContains(CourseCompleted::class, $types);
    }
}
```

- [ ] **Step 4: Uruchom (FAIL→GREEN) + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php` → PASS, pristine.
Run pełny zestaw → zielony.

- [ ] **Step 5: Commit**

```bash
git add src/Progress/Application/MarkLessonCompleted/ tests/Integration/Progress/MarkLessonCompletedIntegrationTest.php
git commit -m "feat(progress): MarkLessonCompleted + publikacja LessonCompleted/CourseCompleted"
```

---

### Task 5: Notification — maile (welcome + congrats), dedupe, konsumenci

**Files:**
- Modify: `src/Identity/Domain/UserRepository.php` (+ `ofId`), `src/Identity/Infrastructure/Doctrine/DoctrineUserRepository.php` (+ `ofId`)
- Modify: `config/packages/doctrine.yaml` (mapowanie Notification)
- Create: `src/Notification/Domain/{SentNotification.php, NotificationType.php, SentNotificationRepository.php}`
- Create: `src/Notification/Application/{Mailer.php, RecipientResolver.php}`
- Create: `src/Notification/Application/SendWelcome/SendWelcomeOnUserEnrolledHandler.php`
- Create: `src/Notification/Application/SendCongrats/SendCongratsOnCourseCompletedHandler.php`
- Create: `src/Notification/Infrastructure/Doctrine/DoctrineSentNotificationRepository.php`
- Create: `src/Notification/Infrastructure/Symfony/SymfonyMailer.php`
- Create: `src/Notification/Infrastructure/Identity/IdentityRecipientResolver.php`
- Modify: `config/services.yaml`
- Create: `migrations/Version*.php`
- Create: `tests/Integration/Notification/NotificationIntegrationTest.php`

**Interfaces:**
- Consumes: `UserEnrolled`, `CourseCompleted`, Identity `UserRepository::ofId`.
- Produces: `NotificationType` enum (`Welcome`, `CourseCompleted`); `SentNotification` (unique typ+user+course); ports `Mailer::send(string $to, string $subject, string $body): void`, `RecipientResolver::emailFor(Uuid $userId): ?string`; handlery (fromTransport `notification_enrollment_in` / `notification_progress_in`).

- [ ] **Step 1: Rozszerz Identity `UserRepository` o `ofId`**

W `src/Identity/Domain/UserRepository.php` dodaj metodę:

```php
    public function ofId(\Symfony\Component\Uid\Uuid $id): ?User;
```

W `src/Identity/Infrastructure/Doctrine/DoctrineUserRepository.php` dodaj implementację:

```php
    public function ofId(\Symfony\Component\Uid\Uuid $id): ?User
    {
        return $this->em->find(User::class, $id);
    }
```

- [ ] **Step 2: Mapowanie Notification w `config/packages/doctrine.yaml`**

```yaml
            Notification:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Notification/Domain'
                prefix: 'App\Notification\Domain'
                alias: Notification
```

- [ ] **Step 3: Enum + encja dedupe**

`src/Notification/Domain/NotificationType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum NotificationType: string
{
    case Welcome = 'welcome';
    case CourseCompleted = 'course_completed';
}
```

`src/Notification/Domain/SentNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'sent_notifications')]
#[ORM\UniqueConstraint(name: 'uniq_notification', columns: ['type', 'user_id', 'course_id'])]
class SentNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $courseId;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(NotificationType $type, Uuid $userId, Uuid $courseId)
    {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->sentAt = new \DateTimeImmutable();
    }
}
```

`src/Notification/Domain/SentNotificationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Symfony\Component\Uid\Uuid;

interface SentNotificationRepository
{
    public function alreadySent(NotificationType $type, Uuid $userId, Uuid $courseId): bool;

    public function save(SentNotification $notification): void;
}
```

- [ ] **Step 4: Porty Application**

`src/Notification/Application/Mailer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Application;

interface Mailer
{
    public function send(string $to, string $subject, string $body): void;
}
```

`src/Notification/Application/RecipientResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Application;

use Symfony\Component\Uid\Uuid;

interface RecipientResolver
{
    public function emailFor(Uuid $userId): ?string;
}
```

- [ ] **Step 5: Handlery**

`src/Notification/Application/SendWelcome/SendWelcomeOnUserEnrolledHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Application\SendWelcome;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Notification\Application\Mailer;
use App\Notification\Application\RecipientResolver;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'notification_enrollment_in')]
final readonly class SendWelcomeOnUserEnrolledHandler
{
    public function __construct(
        private SentNotificationRepository $sent,
        private RecipientResolver $recipients,
        private Mailer $mailer,
    ) {
    }

    public function __invoke(UserEnrolled $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->sent->alreadySent(NotificationType::Welcome, $userId, $courseId)) {
            return;
        }
        $email = $this->recipients->emailFor($userId);
        if (null === $email) {
            return;
        }

        $this->mailer->send($email, 'Witaj na kursie!', 'Zapisałeś się na kurs. Powodzenia w nauce!');
        $this->sent->save(new SentNotification(NotificationType::Welcome, $userId, $courseId));
    }
}
```

`src/Notification/Application/SendCongrats/SendCongratsOnCourseCompletedHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Application\SendCongrats;

use App\Notification\Application\Mailer;
use App\Notification\Application\RecipientResolver;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use App\Progress\Domain\Event\CourseCompleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'notification_progress_in')]
final readonly class SendCongratsOnCourseCompletedHandler
{
    public function __construct(
        private SentNotificationRepository $sent,
        private RecipientResolver $recipients,
        private Mailer $mailer,
    ) {
    }

    public function __invoke(CourseCompleted $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->sent->alreadySent(NotificationType::CourseCompleted, $userId, $courseId)) {
            return;
        }
        $email = $this->recipients->emailFor($userId);
        if (null === $email) {
            return;
        }

        $this->mailer->send($email, 'Gratulacje — kurs ukończony!', 'Ukończyłeś cały kurs. Świetna robota!');
        $this->sent->save(new SentNotification(NotificationType::CourseCompleted, $userId, $courseId));
    }
}
```

- [ ] **Step 6: Adaptery Infrastructure**

`src/Notification/Infrastructure/Doctrine/DoctrineSentNotificationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSentNotificationRepository implements SentNotificationRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function alreadySent(NotificationType $type, Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(SentNotification::class)
            ->count(['type' => $type, 'userId' => $userId, 'courseId' => $courseId]) > 0;
    }

    public function save(SentNotification $notification): void
    {
        $this->em->persist($notification);
        $this->em->flush();
    }
}
```

`src/Notification/Infrastructure/Symfony/SymfonyMailer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Symfony;

use App\Notification\Application\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailer implements Mailer
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $this->mailer->send(
            (new Email())->from('platforma@example.com')->to($to)->subject($subject)->text($body)
        );
    }
}
```

`src/Notification/Infrastructure/Identity/IdentityRecipientResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Identity;

use App\Identity\Domain\UserRepository;
use App\Notification\Application\RecipientResolver;
use Symfony\Component\Uid\Uuid;

final readonly class IdentityRecipientResolver implements RecipientResolver
{
    public function __construct(private UserRepository $users)
    {
    }

    public function emailFor(Uuid $userId): ?string
    {
        return $this->users->ofId($userId)?->getEmail();
    }
}
```

- [ ] **Step 7: Aliasy w `config/services.yaml`**

```yaml
    App\Notification\Domain\SentNotificationRepository:
        alias: App\Notification\Infrastructure\Doctrine\DoctrineSentNotificationRepository

    App\Notification\Application\Mailer:
        alias: App\Notification\Infrastructure\Symfony\SymfonyMailer

    App\Notification\Application\RecipientResolver:
        alias: App\Notification\Infrastructure\Identity\IdentityRecipientResolver
```

- [ ] **Step 8: Migracja `sent_notifications`**

Run: `doctrine:migrations:diff` → tabela `sent_notifications` z unikatem (type, user_id, course_id). Uzupełnij `getDescription()`. Migrate dev + `--env=test`.

- [ ] **Step 9: Test integracyjny `tests/Integration/Notification/NotificationIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Notification\Application\SendWelcome\SendWelcomeOnUserEnrolledHandler;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Test\Constraint as MailerConstraint;
use Symfony\Component\Uid\Uuid;

final class NotificationIntegrationTest extends KernelTestCase
{
    public function testWelcomeMailSentOnceAndIsIdempotent(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        $courseId = Uuid::v4();
        $event = new UserEnrolled((string) $user->getId(), (string) $courseId, '2026-06-24T10:00:00+00:00');

        $handler = self::getContainer()->get(SendWelcomeOnUserEnrolledHandler::class);
        $handler($event);
        $handler($event); // dedupe — drugi raz nie wysyła

        self::assertThat(
            self::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages(),
            self::countOf(1)
        );
    }
}
```

> Asercja maila: w `.env.test` `MAILER_DSN=null://null`; Symfony loguje wysłane wiadomości. Jeśli `mailer.message_logger_listener` jest niedostępne w danej wersji, użyj `Symfony\Component\Mailer\Test\Constraint\EmailCount` przez `MailerAssertionsTrait` w `KernelTestCase` (np. `self::assertQueuedEmailCount`/`getMailerMessages()` — dobierz API zgodne z Symfony 7.4; kluczowa asercja: dokładnie 1 mail po dwóch wywołaniach).

- [ ] **Step 10: Uruchom + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Notification/NotificationIntegrationTest.php` → PASS, pristine.
Run pełny zestaw → zielony.

- [ ] **Step 11: Commit**

```bash
git add src/Identity/ src/Notification/ config/packages/doctrine.yaml config/services.yaml migrations/ tests/Integration/Notification/
git commit -m "feat(notification): maile welcome/congrats (dedupe) + resolver email z Identity"
```

---

### Task 6: UI postępu + weryfikacja end-to-end konsumentów

**Files:**
- Create: `src/Progress/UI/Controller/ProgressController.php`
- Modify: `config/routes.yaml` (routing Progress UI)
- Modify: `templates/catalog/detail.html.twig` (lista lekcji z przyciskiem „Ukończ" + % gdy zalogowany i ma postęp)
- Create: `templates/progress/course.html.twig` (opcjonalnie — widok postępu)
- Modify: `README.md` (jak uruchomić konsumentów + setup-transports)
- Create: `tests/Functional/Progress/ProgressControllerTest.php`

**Interfaces:**
- Consumes: `MarkLessonCompletedCommand` + `CommandBusInterface`, `ProgressRepository`, `User` (Identity).
- Produces: trasa `progress_mark_lesson` (`POST /courses/{courseId}/lessons/{lessonId}/complete`).

- [ ] **Step 1: Kontroler `ProgressController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Progress\UI\Controller;

use App\Identity\Domain\User;
use App\Progress\Application\MarkLessonCompleted\MarkLessonCompletedCommand;
use App\Progress\Domain\Exception\LessonNotInCourseException;
use App\Progress\Domain\Exception\ProgressNotFoundException;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class ProgressController extends AbstractController
{
    #[Route('/courses/{courseId}/lessons/{lessonId}/complete', name: 'progress_mark_lesson', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function markLesson(string $courseId, string $lessonId, Request $request, CommandBusInterface $commandBus): Response
    {
        if (!Uuid::isValid($courseId) || !Uuid::isValid($lessonId)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('complete-'.$lessonId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Nieprawidłowy token CSRF.');

            return $this->redirectToRoute('catalog_detail', ['id' => $courseId]);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Oczekiwano zalogowanego użytkownika.');
        }

        try {
            $commandBus->dispatch(new MarkLessonCompletedCommand($user->getId(), Uuid::fromString($courseId), Uuid::fromString($lessonId)));
            $this->addFlash('success', 'Lekcja oznaczona jako ukończona.');
        } catch (ProgressNotFoundException|LessonNotInCourseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('catalog_detail', ['id' => $courseId]);
    }
}
```

- [ ] **Step 2: Routing `config/routes.yaml`**

```yaml
progress_controllers:
    resource:
        path: ../src/Progress/UI/Controller/
        namespace: App\Progress\UI\Controller
    type: attribute
```

- [ ] **Step 3: Przycisk „Ukończ" przy lekcjach w `templates/catalog/detail.html.twig`**

W pętli lekcji (wewnątrz `{% for lesson in section.lessons %}`) dodaj przy zalogowanym użytkowniku formularz:

```twig
            <li>{{ lesson.title }}
                {% if is_granted('ROLE_USER') %}
                    <form method="post" action="{{ path('progress_mark_lesson', {courseId: course.id, lessonId: lesson.id}) }}" style="display:inline">
                        <input type="hidden" name="_token" value="{{ csrf_token('complete-' ~ lesson.id) }}">
                        <button type="submit">Ukończ</button>
                    </form>
                {% endif %}
            </li>
```

(Zastąp istniejący `<li>{{ lesson.title }}</li>` powyższym.)

- [ ] **Step 4: Test funkcjonalny `tests/Functional/Progress/ProgressControllerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Progress;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Progress\Application\InitProgress\InitProgressOnUserEnrolledHandler;
use App\Progress\Domain\ProgressRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ProgressControllerTest extends WebTestCase
{
    public function testStudentMarksLessonCompleted(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L1', 't'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        // zainicjuj postęp (normalnie robi to konsument Kafki)
        (self::getContainer()->get(InitProgressOnUserEnrolledHandler::class))(
            new UserEnrolled((string) $user->getId(), (string) $courseId, '2026-06-24T10:00:00+00:00')
        );
        $client->loginUser($user);

        $client->request('GET', '/courses/'.$courseId);
        $client->submitForm('Ukończ');

        self::assertResponseRedirects('/courses/'.$courseId);
        $progress = self::getContainer()->get(ProgressRepository::class)->ofUserAndCourse($user->getId(), $courseId);
        self::assertNotNull($progress);
        self::assertTrue($progress->isCompleted());
    }
}
```

> Jeśli na stronie jest wiele przycisków „Ukończ", `submitForm('Ukończ')` kliknie pierwszy — przy jednej lekcji to wystarcza. Dla wielu lekcji dostosuj wybór formularza.

- [ ] **Step 5: README — uruchamianie konsumentów**

W `README.md` dodaj sekcję „Konsumenci Kafki":

```markdown
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
```

- [ ] **Step 6: Uruchom test funkcjonalny + pełny zestaw**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Progress/ProgressControllerTest.php` → PASS, pristine.
Run pełny zestaw (grupa kafka pominięta) → zielony.

- [ ] **Step 7: Weryfikacja end-to-end (ręczna)**

Run: `docker compose up -d`
- Uruchom 3 konsumentów (komendy z README) w tle.
- Zarejestruj studenta, zapisz się na opublikowany kurs → w Mailpit pojawia się mail powitalny; postęp zainicjowany.
- Oznacz wszystkie lekcje jako ukończone → po `CourseCompleted` w Mailpit pojawia się mail gratulacyjny.
- Eventy widoczne w Kafka UI (`enrollment.events`, `progress.events`) z osobnymi consumer groups.

> Weryfikacja manualna (nie test) — potwierdza pełny event-driven flow MVP.

- [ ] **Step 8: Commit**

```bash
git add src/Progress/UI/ config/routes.yaml templates/ README.md tests/Functional/Progress/
git commit -m "feat(progress): UI oznaczania lekcji ukończonej + dokumentacja konsumentów Kafki"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu:**
- Konsument Progress inicjuje postęp z `UserEnrolled` → Task 3 ✅
- Oznaczanie lekcji, % ukończenia, wykrycie ukończenia kursu → Tasks 2,4,6 ✅
- `LessonCompleted`/`CourseCompleted` publikowane na Kafkę → Tasks 2,4 ✅
- Konsument Notification: welcome (UserEnrolled) + congrats (CourseCompleted) → Task 5 ✅
- Osobne consumer groups (progress/notification) → Task 1 (transporty + grupy) + handlery fromTransport ✅
- Idempotencja konsumentów (Kafka at-least-once) → Progress skip-if-exists (Task 3) + set-based mark (Task 2/4) + Notification dedupe (Task 5) ✅
- Dead-letter (failed transport) + retry → Task 1 ✅
- Maile w Mailpit → Task 5/6 ✅
- Testy: unit (Task 2), integration (Tasks 3,4,5), functional (Task 6); realnej Kafki dotyka tylko smoke z Fazy 4 ✅
- Definicja ukończenia MVP (spec §9): wszystkie pozycje pokryte po Fazach 1-5 ✅

**Placeholdery:** brak; migracje generowane (`migrations:diff`) — celowe.

**Spójność typów:** `ProgressRepository`/`CourseStructureProvider`/`SentNotificationRepository`/`Mailer`/`RecipientResolver` — sygnatury spójne między definicją a użyciem. Eventy `LessonCompleted`/`CourseCompleted` (string fields) spójne (Task 2 def, Task 4 publish, Task 5 consume). `MarkLessonCompletedCommand(Uuid×3)` spójne (Task 4, Task 6 UI). `UserRepository::ofId(Uuid): ?User` dodane (Task 5) i użyte w resolverze.

**Świadome decyzje:**
- Osobne transporty per consumer group + `fromTransport` na handlerach → realne, niezależne consumer groups (showcase Kafki). Testy wołają handlery bezpośrednio (sync), więc nie zależą od pętli `messenger:consume`.
- Idempotencja na trzech poziomach (unikaty DB + skip + dedupe) — bezpieczne przy at-least-once.
- `totalLessons` snapshotowane przy inicjalizacji postępu (lekcje dodane później nie zmieniają licznika) — akceptowalne dla MVP; do rozważenia recompute w przyszłości.
- Email rozwiązywany przez `RecipientResolver` → Identity `ofId` (zamiast wzbogacania eventu o email) — lokalizuje zależność w Infrastructure Notification, event pozostaje minimalny.
- Maile w testach: `null://null` + asercja licznika (dedupe = dokładnie 1).

**Uwagi wykonawcze:**
- Routing eventów Progress dodać dopiero po utworzeniu klas (Task 2) — Symfony 7 waliduje klasę routingu przy kompilacji (jak w Fazie 4).
- `failed` (doctrine) wymaga tabeli `messenger_messages` — `messenger:setup-transports failed` (dev; fresh env w README). Testy nie dotykają failure_transport (handlery wołane wprost), więc tabela nie jest wymagana do zielonego zestawu.
- API asercji maili dobrać do Symfony 7.4 (`MailerAssertionsTrait`/`getMailerMessages()` w KernelTestCase) — kluczowe: dokładnie 1 mail po dwóch wywołaniach (dedupe).
- `messenger.transport.kafka_progress` w teście to `InMemoryTransport` (`.env.test`), więc asercja `getSent()` w Tasku 4 działa bez brokera.
```
