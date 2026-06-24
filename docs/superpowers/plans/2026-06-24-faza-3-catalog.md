# Faza 3: Catalog (kursy, sekcje, lekcje) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować moduł **Catalog**: instruktor tworzy kurs z sekcjami i lekcjami oraz publikuje go; student przegląda katalog opublikowanych kursów i ich szczegóły — wg hexagonal + lekkie DDD, z agregatem `Course`.

**Architecture:** Bounded context `Catalog` (Domain/Application/Infrastructure/UI). Agregat `Course` (korzeń) zawiera `Section`, a `Section` zawiera `Lesson` (Doctrine OneToMany z kaskadą; mutacje wyłącznie przez metody korzenia — niezmienniki: pozycje, publikacja). Strona poleceń przez `command.bus`; strona zapytań przez nowy `query.bus` (zwraca wartości). Brak współdzielenia encji między modułami: `Course.instructorId` to `Uuid` użytkownika z modułu Identity (bez relacji ORM).

**Tech Stack:** Symfony 7.4 (Form, Security, Validator), symfony/uid, Doctrine ORM + migrations, Twig, PHPUnit, dama/doctrine-test-bundle (już skonfigurowane).

## Global Constraints

- PHP **8.4**, Symfony **7.x**; baza **MySQL 8.4**. Komendy w kontenerze: `docker compose run --rm php <cmd>` (host bez PHP). DB: najpierw `docker compose up -d mysql`.
- Namespace PSR-4: `App\Catalog\{Domain,Application,Infrastructure,UI}`. **Domain i Application bez zależności od frameworka** (dozwolony tylko atrybut `#[AsMessageHandler]` na handlerach oraz atrybuty mapowania Doctrine `#[ORM\...]` na encjach — jak w module Identity).
- **Nazewnictwo:** „sekcja kursu" ze specu (§4 „Module") realizujemy jako encję **`Section`** — unikamy kolizji ze słowem „moduł" (= bounded context). To ten sam koncept domenowy.
- Komunikacja: polecenia przez `command.bus`, zapytania przez `query.bus` (oba istnieją z Fazy 1; handlery oznaczane `#[AsMessageHandler(bus: '...')]`).
- Granice modułów: Catalog NIE importuje encji z Identity. `instructorId` to `Uuid` (tożsamość użytkownika) przekazywany z kontrolera (`$this->getUser()->getId()`).
- Autoryzacja: akcje instruktora pod prefiksem `/instructor` chronione `ROLE_INSTRUCTOR` (access_control) **oraz** sprawdzeniem własności w handlerach (instruktor edytuje tylko swoje kursy).
- Testy: piramida (unit/integration/functional), izolacja DB (DAMA), pristine output (0 deprecations/warningów/notice).
- **Commity bez śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code"). Git user: `konrad`.
- Każdy task kończy się commitem.

---

## Struktura plików (tworzona/zmieniana)

```
src/Shared/
  Application/Bus/QueryBusInterface.php          # nowy port zapytań
  Infrastructure/Bus/MessengerQueryBus.php       # adapter nad query.bus
src/Catalog/
  Domain/
    Course.php          # agregat (korzeń)
    Section.php         # część agregatu
    Lesson.php          # część agregatu
    CourseStatus.php    # enum Draft/Published
    CourseRepository.php # port
    Exception/
      CourseNotFoundException.php
      NotCourseOwnerException.php
      SectionNotFoundException.php
      CannotPublishCourseWithoutLessonsException.php
  Application/
    CreateCourse/{CreateCourseCommand.php,CreateCourseHandler.php}
    AddSection/{AddSectionCommand.php,AddSectionHandler.php}
    AddLesson/{AddLessonCommand.php,AddLessonHandler.php}
    PublishCourse/{PublishCourseCommand.php,PublishCourseHandler.php}
    FindPublishedCourses/{FindPublishedCoursesQuery.php,FindPublishedCoursesHandler.php}
    FindPublishedCourse/{FindPublishedCourseQuery.php,FindPublishedCourseHandler.php}
    FindInstructorCourses/{FindInstructorCoursesQuery.php,FindInstructorCoursesHandler.php}
  Infrastructure/Doctrine/DoctrineCourseRepository.php
  UI/
    Controller/
      CourseCatalogController.php     # GET /courses
      CourseDetailController.php      # GET /courses/{id}
      InstructorCourseController.php  # /instructor/courses* (lista, new, manage, add section/lesson, publish)
    Form/{CourseForm.php,CourseFormData.php,SectionForm.php,SectionFormData.php,LessonForm.php,LessonFormData.php}
migrations/Version*.php               # courses, course_sections, course_lessons
templates/catalog/{list.html.twig,detail.html.twig}
templates/instructor/{courses.html.twig,new_course.html.twig,manage_course.html.twig}
config/packages/doctrine.yaml         # mapowanie Catalog
config/packages/security.yaml         # access_control ^/instructor
config/routes.yaml                    # routing src/Catalog/UI/Controller
config/services.yaml                  # aliasy QueryBus + CourseRepository
tests/Unit/Catalog/... Integration/Catalog/... Functional/Catalog/...
```

---

### Task 1: Shared QueryBus (port + adapter)

**Files:**
- Create: `src/Shared/Application/Bus/QueryBusInterface.php`
- Create: `src/Shared/Infrastructure/Bus/MessengerQueryBus.php`
- Modify: `config/services.yaml`
- Test: `tests/Unit/Shared/MessengerQueryBusTest.php`

**Interfaces:**
- Produces: `interface App\Shared\Application\Bus\QueryBusInterface { public function ask(object $query): mixed; }`; `MessengerQueryBus implements QueryBusInterface` nad busem `query.bus`, zwraca wynik z `HandledStamp` i odpakowuje `HandlerFailedException`.

- [ ] **Step 1: Napisz failujący test `tests/Unit/Shared/MessengerQueryBusTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Bus\MessengerQueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandledStamp;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBusTest extends TestCase
{
    public function testReturnsHandlerResult(): void
    {
        $query = new \stdClass();
        $inner = $this->createMock(MessageBusInterface::class);
        $inner->method('dispatch')->willReturn(
            (new Envelope($query))->with(new HandledStamp('wynik', 'handler'))
        );

        $bus = new MessengerQueryBus($inner);

        self::assertSame('wynik', $bus->ask($query));
    }
}
```

- [ ] **Step 2: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Shared/MessengerQueryBusTest.php`
Expected: FAIL — `MessengerQueryBus` nie istnieje.

- [ ] **Step 3: Utwórz `src/Shared/Application/Bus/QueryBusInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface QueryBusInterface
{
    public function ask(object $query): mixed;
}
```

- [ ] **Step 4: Utwórz `src/Shared/Infrastructure/Bus/MessengerQueryBus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandledStamp;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerQueryBus implements QueryBusInterface
{
    public function __construct(private MessageBusInterface $queryBus)
    {
    }

    public function ask(object $query): mixed
    {
        try {
            $envelope = $this->queryBus->dispatch($query);
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        }

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);

        return $handled?->getResult();
    }
}
```

- [ ] **Step 5: Zwiąż w `config/services.yaml`**

W sekcji `services:` dodaj (analogicznie do istniejącego wiązania `MessengerCommandBus`):

```yaml
    App\Shared\Infrastructure\Bus\MessengerQueryBus:
        arguments:
            $queryBus: '@query.bus'

    App\Shared\Application\Bus\QueryBusInterface:
        alias: App\Shared\Infrastructure\Bus\MessengerQueryBus
```

- [ ] **Step 6: Uruchom test — ma PRZEJŚĆ + lint kontenera**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Shared/MessengerQueryBusTest.php`
Expected: PASS, pristine.

Run: `docker compose run --rm php bin/console lint:container`
Expected: `The container was linted successfully`.

- [ ] **Step 7: Commit**

```bash
git add src/Shared/ config/services.yaml tests/Unit/Shared/
git commit -m "feat(shared): QueryBus (port + adapter Messenger nad query.bus)"
```

---

### Task 2: Domena Catalog — agregat Course (Section, Lesson), enum, mapowanie, migracja

**Files:**
- Modify: `config/packages/doctrine.yaml` (mapowanie `Catalog`)
- Create: `src/Catalog/Domain/CourseStatus.php`
- Create: `src/Catalog/Domain/Lesson.php`
- Create: `src/Catalog/Domain/Section.php`
- Create: `src/Catalog/Domain/Course.php`
- Create: `src/Catalog/Domain/Exception/SectionNotFoundException.php`
- Create: `src/Catalog/Domain/Exception/CannotPublishCourseWithoutLessonsException.php`
- Create: `tests/Unit/Catalog/Domain/CourseTest.php`
- Create: `migrations/Version*.php`

**Interfaces:**
- Produces:
  - `enum App\Catalog\Domain\CourseStatus: string { case Draft = 'draft'; case Published = 'published'; }`
  - `Course` (korzeń): `static create(Uuid $id, Uuid $instructorId, string $title, string $description): self`; `addSection(Uuid $sectionId, string $title): void`; `addLessonToSection(Uuid $sectionId, Uuid $lessonId, string $title, string $content): void` (rzuca `SectionNotFoundException`); `publish(): void` (rzuca `CannotPublishCourseWithoutLessonsException` gdy 0 lekcji); `isPublished(): bool`; `belongsTo(Uuid $instructorId): bool`; `totalLessons(): int`; gettery `getId(): Uuid`, `getInstructorId(): Uuid`, `getTitle(): string`, `getDescription(): string`, `getStatus(): CourseStatus`, `getSections(): array`.
  - `Section`: `getId(): Uuid`, `getTitle(): string`, `getPosition(): int`, `getLessons(): array`, `lessonCount(): int`, `addLesson(Uuid,string,string): void`.
  - `Lesson`: `getId(): Uuid`, `getTitle(): string`, `getContent(): string`, `getPosition(): int`.

- [ ] **Step 1: Dodaj mapowanie w `config/packages/doctrine.yaml`**

W `doctrine.orm.mappings` dopisz drugi wpis (obok `Identity`):

```yaml
            Catalog:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Catalog/Domain'
                prefix: 'App\Catalog\Domain'
                alias: Catalog
```

- [ ] **Step 2: Utwórz enum `src/Catalog/Domain/CourseStatus.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
```

- [ ] **Step 3: Utwórz wyjątki domenowe**

`src/Catalog/Domain/Exception/SectionNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class SectionNotFoundException extends \DomainException
{
    public static function withId(string $sectionId): self
    {
        return new self(sprintf('Sekcja "%s" nie istnieje w tym kursie.', $sectionId));
    }
}
```

`src/Catalog/Domain/Exception/CannotPublishCourseWithoutLessonsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class CannotPublishCourseWithoutLessonsException extends \DomainException
{
    public static function create(): self
    {
        return new self('Nie można opublikować kursu bez żadnej lekcji.');
    }
}
```

- [ ] **Step 4: Utwórz `src/Catalog/Domain/Lesson.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'course_lessons')]
class Lesson
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false)]
    private Section $section;

    public function __construct(Section $section, Uuid $id, string $title, string $content, int $position)
    {
        $this->section = $section;
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
```

- [ ] **Step 5: Utwórz `src/Catalog/Domain/Section.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'course_sections')]
class Section
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false)]
    private Course $course;

    /** @var Collection<int, Lesson> */
    #[ORM\OneToMany(targetEntity: Lesson::class, mappedBy: 'section', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lessons;

    public function __construct(Course $course, Uuid $id, string $title, int $position)
    {
        $this->course = $course;
        $this->id = $id;
        $this->title = $title;
        $this->position = $position;
        $this->lessons = new ArrayCollection();
    }

    public function addLesson(Uuid $lessonId, string $title, string $content): void
    {
        $this->lessons->add(new Lesson($this, $lessonId, $title, $content, $this->lessons->count() + 1));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** @return list<Lesson> */
    public function getLessons(): array
    {
        return array_values($this->lessons->toArray());
    }

    public function lessonCount(): int
    {
        return $this->lessons->count();
    }
}
```

- [ ] **Step 6: Napisz failujący test `tests/Unit/Catalog/Domain/CourseTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\CourseStatus;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Exception\CannotPublishCourseWithoutLessonsException;
use App\Catalog\Domain\Exception\SectionNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CourseTest extends TestCase
{
    public function testNewCourseIsDraftAndOwnedByInstructor(): void
    {
        $instructorId = Uuid::v4();
        $course = Course::create(Uuid::v4(), $instructorId, 'Symfony 101', 'Opis');

        self::assertSame(CourseStatus::Draft, $course->getStatus());
        self::assertFalse($course->isPublished());
        self::assertTrue($course->belongsTo($instructorId));
        self::assertFalse($course->belongsTo(Uuid::v4()));
        self::assertSame('Symfony 101', $course->getTitle());
    }

    public function testAddSectionAndLessonAssignsSequentialPositions(): void
    {
        $course = Course::create(Uuid::v4(), Uuid::v4(), 'Kurs', 'Opis');
        $s1 = Uuid::v4();
        $course->addSection($s1, 'Wstęp');
        $course->addLessonToSection($s1, Uuid::v4(), 'Lekcja 1', 'treść');
        $course->addLessonToSection($s1, Uuid::v4(), 'Lekcja 2', 'treść');

        $sections = $course->getSections();
        self::assertCount(1, $sections);
        self::assertSame(1, $sections[0]->getPosition());
        $lessons = $sections[0]->getLessons();
        self::assertSame([1, 2], [$lessons[0]->getPosition(), $lessons[1]->getPosition()]);
        self::assertSame(2, $course->totalLessons());
    }

    public function testAddLessonToMissingSectionThrows(): void
    {
        $course = Course::create(Uuid::v4(), Uuid::v4(), 'Kurs', 'Opis');

        $this->expectException(SectionNotFoundException::class);
        $course->addLessonToSection(Uuid::v4(), Uuid::v4(), 'L', 't');
    }

    public function testPublishRequiresAtLeastOneLesson(): void
    {
        $course = Course::create(Uuid::v4(), Uuid::v4(), 'Kurs', 'Opis');
        $course->addSection(Uuid::v4(), 'Pusta sekcja');

        $this->expectException(CannotPublishCourseWithoutLessonsException::class);
        $course->publish();
    }

    public function testPublishSucceedsWithLesson(): void
    {
        $course = Course::create(Uuid::v4(), Uuid::v4(), 'Kurs', 'Opis');
        $s = Uuid::v4();
        $course->addSection($s, 'Sekcja');
        $course->addLessonToSection($s, Uuid::v4(), 'Lekcja', 'treść');

        $course->publish();

        self::assertTrue($course->isPublished());
        self::assertSame(CourseStatus::Published, $course->getStatus());
    }
}
```

- [ ] **Step 7: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Catalog/Domain/CourseTest.php`
Expected: FAIL — `Course` nie istnieje.

- [ ] **Step 8: Utwórz `src/Catalog/Domain/Course.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\CannotPublishCourseWithoutLessonsException;
use App\Catalog\Domain\Exception\SectionNotFoundException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'courses')]
class Course
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $instructorId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(enumType: CourseStatus::class)]
    private CourseStatus $status;

    /** @var Collection<int, Section> */
    #[ORM\OneToMany(targetEntity: Section::class, mappedBy: 'course', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $sections;

    private function __construct(Uuid $id, Uuid $instructorId, string $title, string $description)
    {
        $this->id = $id;
        $this->instructorId = $instructorId;
        $this->title = $title;
        $this->description = $description;
        $this->status = CourseStatus::Draft;
        $this->sections = new ArrayCollection();
    }

    public static function create(Uuid $id, Uuid $instructorId, string $title, string $description): self
    {
        return new self($id, $instructorId, $title, $description);
    }

    public function addSection(Uuid $sectionId, string $title): void
    {
        $this->sections->add(new Section($this, $sectionId, $title, $this->sections->count() + 1));
    }

    public function addLessonToSection(Uuid $sectionId, Uuid $lessonId, string $title, string $content): void
    {
        foreach ($this->sections as $section) {
            if ($section->getId()->equals($sectionId)) {
                $section->addLesson($lessonId, $title, $content);

                return;
            }
        }

        throw SectionNotFoundException::withId((string) $sectionId);
    }

    public function publish(): void
    {
        if (0 === $this->totalLessons()) {
            throw CannotPublishCourseWithoutLessonsException::create();
        }

        $this->status = CourseStatus::Published;
    }

    public function isPublished(): bool
    {
        return CourseStatus::Published === $this->status;
    }

    public function belongsTo(Uuid $instructorId): bool
    {
        return $this->instructorId->equals($instructorId);
    }

    public function totalLessons(): int
    {
        $total = 0;
        foreach ($this->sections as $section) {
            $total += $section->lessonCount();
        }

        return $total;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInstructorId(): Uuid
    {
        return $this->instructorId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): CourseStatus
    {
        return $this->status;
    }

    /** @return list<Section> */
    public function getSections(): array
    {
        return array_values($this->sections->toArray());
    }
}
```

- [ ] **Step 9: Uruchom test — ma PRZEJŚĆ + walidacja mapowania**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Catalog/Domain/CourseTest.php`
Expected: PASS (5 testów), pristine.

Run: `docker compose up -d mysql && docker compose run --rm php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 10: Wygeneruj i zastosuj migrację (3 tabele)**

Run: `docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction`
Expected: nowy `migrations/Version*.php` z `CREATE TABLE courses`, `course_sections`, `course_lessons` + klucze obce (section→course, lesson→section). Uzupełnij `getDescription()` jednolinijkowo (np. `return 'Tworzy tabele Catalog (courses, sections, lessons).';`).

Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction`
Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction --env=test`
Expected: obie migracje zastosowane (dev + test).

- [ ] **Step 11: Commit**

```bash
git add src/Catalog/Domain/ tests/Unit/Catalog/ config/packages/doctrine.yaml migrations/
git commit -m "feat(catalog): agregat Course (Section, Lesson) + enum, mapowanie ORM i migracja"
```

---

### Task 3: Application — strona poleceń (Create/AddSection/AddLesson/Publish)

**Files:**
- Create: `src/Catalog/Domain/CourseRepository.php`
- Create: `src/Catalog/Domain/Exception/CourseNotFoundException.php`
- Create: `src/Catalog/Domain/Exception/NotCourseOwnerException.php`
- Create: `src/Catalog/Application/CreateCourse/{CreateCourseCommand.php,CreateCourseHandler.php}`
- Create: `src/Catalog/Application/AddSection/{AddSectionCommand.php,AddSectionHandler.php}`
- Create: `src/Catalog/Application/AddLesson/{AddLessonCommand.php,AddLessonHandler.php}`
- Create: `src/Catalog/Application/PublishCourse/{PublishCourseCommand.php,PublishCourseHandler.php}`
- Create: `tests/Unit/Catalog/Application/CourseCommandHandlersTest.php`

**Interfaces:**
- Consumes: `Course`, `CourseStatus` (Task 2).
- Produces:
  - `interface App\Catalog\Domain\CourseRepository { public function save(Course $course): void; public function ofId(Uuid $id): ?Course; public function allPublished(): array; public function ofInstructor(Uuid $instructorId): array; }`
  - Polecenia (readonly) i handlery (`#[AsMessageHandler(bus: 'command.bus')]`):
    - `CreateCourseCommand(Uuid $courseId, Uuid $instructorId, string $title, string $description)`
    - `AddSectionCommand(Uuid $courseId, Uuid $sectionId, Uuid $instructorId, string $title)`
    - `AddLessonCommand(Uuid $courseId, Uuid $sectionId, Uuid $lessonId, Uuid $instructorId, string $title, string $content)`
    - `PublishCourseCommand(Uuid $courseId, Uuid $instructorId)`
  - Handlery load→ownership→mutate→save; rzucają `CourseNotFoundException` / `NotCourseOwnerException`.

- [ ] **Step 1: Utwórz port `src/Catalog/Domain/CourseRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use Symfony\Component\Uid\Uuid;

interface CourseRepository
{
    public function save(Course $course): void;

    public function ofId(Uuid $id): ?Course;

    /** @return list<Course> */
    public function allPublished(): array;

    /** @return list<Course> */
    public function ofInstructor(Uuid $instructorId): array;
}
```

- [ ] **Step 2: Utwórz wyjątki**

`src/Catalog/Domain/Exception/CourseNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class CourseNotFoundException extends \DomainException
{
    public static function withId(string $courseId): self
    {
        return new self(sprintf('Kurs "%s" nie istnieje.', $courseId));
    }
}
```

`src/Catalog/Domain/Exception/NotCourseOwnerException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class NotCourseOwnerException extends \DomainException
{
    public static function create(): self
    {
        return new self('Nie jesteś właścicielem tego kursu.');
    }
}
```

- [ ] **Step 3: Utwórz polecenia (4 pliki Command)**

`src/Catalog/Application/CreateCourse/CreateCourseCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

use Symfony\Component\Uid\Uuid;

final readonly class CreateCourseCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $instructorId,
        public string $title,
        public string $description,
    ) {
    }
}
```

`src/Catalog/Application/AddSection/AddSectionCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddSection;

use Symfony\Component\Uid\Uuid;

final readonly class AddSectionCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $sectionId,
        public Uuid $instructorId,
        public string $title,
    ) {
    }
}
```

`src/Catalog/Application/AddLesson/AddLessonCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddLesson;

use Symfony\Component\Uid\Uuid;

final readonly class AddLessonCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $sectionId,
        public Uuid $lessonId,
        public Uuid $instructorId,
        public string $title,
        public string $content,
    ) {
    }
}
```

`src/Catalog/Application/PublishCourse/PublishCourseCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishCourse;

use Symfony\Component\Uid\Uuid;

final readonly class PublishCourseCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $instructorId,
    ) {
    }
}
```

- [ ] **Step 4: Napisz failujący test `tests/Unit/Catalog/Application/CourseCommandHandlersTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddLesson\AddLessonHandler;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\AddSection\AddSectionHandler;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\CreateCourse\CreateCourseHandler;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseHandler;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CourseCommandHandlersTest extends TestCase
{
    private function repo(): CourseRepository
    {
        return new class implements CourseRepository {
            /** @var array<string, Course> */
            public array $store = [];
            public function save(Course $course): void { $this->store[(string) $course->getId()] = $course; }
            public function ofId(Uuid $id): ?Course { return $this->store[(string) $id] ?? null; }
            public function allPublished(): array { return array_values(array_filter($this->store, fn (Course $c) => $c->isPublished())); }
            public function ofInstructor(Uuid $instructorId): array { return array_values(array_filter($this->store, fn (Course $c) => $c->belongsTo($instructorId))); }
        };
    }

    public function testFullAuthoringFlow(): void
    {
        $repo = $this->repo();
        $instructor = Uuid::v4();
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();

        (new CreateCourseHandler($repo))(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        (new AddSectionHandler($repo))(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        (new AddLessonHandler($repo))(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        (new PublishCourseHandler($repo))(new PublishCourseCommand($courseId, $instructor));

        $course = $repo->ofId($courseId);
        self::assertNotNull($course);
        self::assertTrue($course->isPublished());
        self::assertSame(1, $course->totalLessons());
    }

    public function testAddSectionByNonOwnerThrows(): void
    {
        $repo = $this->repo();
        $owner = Uuid::v4();
        $courseId = Uuid::v4();
        (new CreateCourseHandler($repo))(new CreateCourseCommand($courseId, $owner, 'Kurs', 'Opis'));

        $this->expectException(NotCourseOwnerException::class);
        (new AddSectionHandler($repo))(new AddSectionCommand($courseId, Uuid::v4(), Uuid::v4(), 'Sekcja'));
    }

    public function testPublishMissingCourseThrows(): void
    {
        $repo = $this->repo();

        $this->expectException(CourseNotFoundException::class);
        (new PublishCourseHandler($repo))(new PublishCourseCommand(Uuid::v4(), Uuid::v4()));
    }
}
```

- [ ] **Step 5: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Catalog/Application/CourseCommandHandlersTest.php`
Expected: FAIL — handlery nie istnieją.

- [ ] **Step 6: Utwórz handler `CreateCourseHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(CreateCourseCommand $command): void
    {
        $this->courses->save(
            Course::create($command->courseId, $command->instructorId, $command->title, $command->description)
        );
    }
}
```

- [ ] **Step 7: Utwórz handler `AddSectionHandler.php` (ze wspólnym wzorcem load+ownership)**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddSection;

use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddSectionHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(AddSectionCommand $command): void
    {
        $course = $this->courses->ofId($command->courseId);
        if (null === $course) {
            throw CourseNotFoundException::withId((string) $command->courseId);
        }
        if (!$course->belongsTo($command->instructorId)) {
            throw NotCourseOwnerException::create();
        }

        $course->addSection($command->sectionId, $command->title);
        $this->courses->save($course);
    }
}
```

- [ ] **Step 8: Utwórz handler `AddLessonHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddLesson;

use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddLessonHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(AddLessonCommand $command): void
    {
        $course = $this->courses->ofId($command->courseId);
        if (null === $course) {
            throw CourseNotFoundException::withId((string) $command->courseId);
        }
        if (!$course->belongsTo($command->instructorId)) {
            throw NotCourseOwnerException::create();
        }

        $course->addLessonToSection($command->sectionId, $command->lessonId, $command->title, $command->content);
        $this->courses->save($course);
    }
}
```

- [ ] **Step 9: Utwórz handler `PublishCourseHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishCourse;

use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PublishCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(PublishCourseCommand $command): void
    {
        $course = $this->courses->ofId($command->courseId);
        if (null === $course) {
            throw CourseNotFoundException::withId((string) $command->courseId);
        }
        if (!$course->belongsTo($command->instructorId)) {
            throw NotCourseOwnerException::create();
        }

        $course->publish();
        $this->courses->save($course);
    }
}
```

- [ ] **Step 10: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Catalog/Application/CourseCommandHandlersTest.php`
Expected: PASS (3 testy), pristine.

- [ ] **Step 11: Commit**

```bash
git add src/Catalog/Domain/CourseRepository.php src/Catalog/Domain/Exception/CourseNotFoundException.php src/Catalog/Domain/Exception/NotCourseOwnerException.php src/Catalog/Application/ tests/Unit/Catalog/Application/
git commit -m "feat(catalog): polecenia Create/AddSection/AddLesson/Publish + port CourseRepository"
```

---

### Task 4: Infrastructure — DoctrineCourseRepository + test integracyjny

**Files:**
- Create: `src/Catalog/Infrastructure/Doctrine/DoctrineCourseRepository.php`
- Modify: `config/services.yaml` (alias `CourseRepository`)
- Create: `tests/Integration/Catalog/CourseAuthoringIntegrationTest.php`

**Interfaces:**
- Consumes: `CourseRepository`, polecenia/handlery (Task 3), `CommandBusInterface` (Faza 1).
- Produces: `DoctrineCourseRepository implements CourseRepository`.

- [ ] **Step 1: Zaimplementuj `src/Catalog/Infrastructure/Doctrine/DoctrineCourseRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\CourseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCourseRepository implements CourseRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Course $course): void
    {
        $this->em->persist($course);
        $this->em->flush();
    }

    public function ofId(Uuid $id): ?Course
    {
        return $this->em->find(Course::class, $id);
    }

    public function allPublished(): array
    {
        return array_values(
            $this->em->getRepository(Course::class)->findBy(['status' => CourseStatus::Published], ['title' => 'ASC'])
        );
    }

    public function ofInstructor(Uuid $instructorId): array
    {
        return array_values(
            $this->em->getRepository(Course::class)->findBy(['instructorId' => $instructorId], ['title' => 'ASC'])
        );
    }
}
```

- [ ] **Step 2: Dodaj alias w `config/services.yaml`**

```yaml
    App\Catalog\Domain\CourseRepository:
        alias: App\Catalog\Infrastructure\Doctrine\DoctrineCourseRepository
```

- [ ] **Step 3: Napisz failujący test `tests/Integration/Catalog/CourseAuthoringIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Domain\CourseRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CourseAuthoringIntegrationTest extends KernelTestCase
{
    public function testAuthorAndPublishPersistsAggregate(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courses = self::getContainer()->get(CourseRepository::class);

        $instructor = Uuid::v4();
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();

        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Symfony', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Wstęp'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja 1', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        self::getContainer()->get('doctrine')->getManager()->clear();

        $course = $courses->ofId($courseId);
        self::assertNotNull($course);
        self::assertTrue($course->isPublished());
        self::assertCount(1, $course->getSections());
        self::assertCount(1, $course->getSections()[0]->getLessons());
        self::assertCount(1, $courses->allPublished());
    }
}
```

- [ ] **Step 4: Uruchom test — ma FAILOWAĆ, potem (po aliasie/impl) PRZEJŚĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Catalog/CourseAuthoringIntegrationTest.php`
Expected najpierw FAIL (brak aliasu/implementacji), po krokach 1-2 PASS (1 test), pristine. Wywołanie `getManager()->clear()` wymusza ponowne wczytanie agregatu z bazy (dowód realnej persystencji kaskady).

- [ ] **Step 5: Pełny zestaw (regresja)**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: wszystko zielone, pristine.

- [ ] **Step 6: Commit**

```bash
git add src/Catalog/Infrastructure/ config/services.yaml tests/Integration/Catalog/
git commit -m "feat(catalog): DoctrineCourseRepository + test integracyjny autoringu"
```

---

### Task 5: Strona zapytań + UI przeglądania (student)

**Files:**
- Create: `src/Catalog/Application/FindPublishedCourses/{FindPublishedCoursesQuery.php,FindPublishedCoursesHandler.php}`
- Create: `src/Catalog/Application/FindPublishedCourse/{FindPublishedCourseQuery.php,FindPublishedCourseHandler.php}`
- Create: `src/Catalog/UI/Controller/CourseCatalogController.php`
- Create: `src/Catalog/UI/Controller/CourseDetailController.php`
- Modify: `config/routes.yaml` (routing Catalog)
- Create: `templates/catalog/list.html.twig`, `templates/catalog/detail.html.twig`
- Create: `tests/Functional/Catalog/CourseBrowsingTest.php`

**Interfaces:**
- Consumes: `CourseRepository` (Task 3/4), `QueryBusInterface` (Task 1), `Course` (Task 2), `CreateCourse*/AddSection*/AddLesson*/PublishCourse*` (do seedowania w teście).
- Produces:
  - `FindPublishedCoursesQuery` (bez argumentów) → handler zwraca `list<Course>` (`#[AsMessageHandler(bus: 'query.bus')]`).
  - `FindPublishedCourseQuery(Uuid $courseId)` → handler zwraca `?Course` (tylko jeśli opublikowany).
  - trasy `catalog_list` (`GET /courses`), `catalog_detail` (`GET /courses/{id}`).

- [ ] **Step 1: Utwórz `FindPublishedCoursesQuery.php` + handler**

`src/Catalog/Application/FindPublishedCourses/FindPublishedCoursesQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourses;

final readonly class FindPublishedCoursesQuery
{
}
```

`src/Catalog/Application/FindPublishedCourses/FindPublishedCoursesHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourses;

use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindPublishedCoursesHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    /** @return list<\App\Catalog\Domain\Course> */
    public function __invoke(FindPublishedCoursesQuery $query): array
    {
        return $this->courses->allPublished();
    }
}
```

- [ ] **Step 2: Utwórz `FindPublishedCourseQuery.php` + handler**

`src/Catalog/Application/FindPublishedCourse/FindPublishedCourseQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourse;

use Symfony\Component\Uid\Uuid;

final readonly class FindPublishedCourseQuery
{
    public function __construct(public Uuid $courseId)
    {
    }
}
```

`src/Catalog/Application/FindPublishedCourse/FindPublishedCourseHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourse;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindPublishedCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(FindPublishedCourseQuery $query): ?Course
    {
        $course = $this->courses->ofId($query->courseId);

        return $course?->isPublished() ? $course : null;
    }
}
```

- [ ] **Step 3: Dodaj routing Catalog w `config/routes.yaml`**

Dopisz:

```yaml
catalog_controllers:
    resource:
        path: ../src/Catalog/UI/Controller/
        namespace: App\Catalog\UI\Controller
    type: attribute
```

- [ ] **Step 4: Napisz failujący test `tests/Functional/Catalog/CourseBrowsingTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class CourseBrowsingTest extends WebTestCase
{
    private function publishCourse(CommandBusInterface $bus, string $title): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, $title, 'Opis kursu'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja A', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testCatalogListsPublishedCoursesOnly(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $this->publishCourse($bus, 'Opublikowany Kurs');
        // kurs w wersji roboczej (bez publikacji)
        $bus->dispatch(new CreateCourseCommand(Uuid::v4(), Uuid::v4(), 'Roboczy Kurs', 'Opis'));

        $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Opublikowany Kurs');
        self::assertStringNotContainsString('Roboczy Kurs', $client->getResponse()->getContent());
    }

    public function testCourseDetailShowsSectionsAndLessons(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus, 'Kurs Szczegółowy');

        $client->request('GET', '/courses/'.$courseId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Kurs Szczegółowy');
        self::assertSelectorTextContains('body', 'Sekcja');
        self::assertSelectorTextContains('body', 'Lekcja A');
    }

    public function testUnpublishedOrMissingCourseReturns404(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $draftId = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($draftId, Uuid::v4(), 'Roboczy', 'Opis'));

        $client->request('GET', '/courses/'.$draftId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/courses/'.Uuid::v4());
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 5: Uruchom test — ma FAILOWAĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Catalog/CourseBrowsingTest.php`
Expected: FAIL — trasy `/courses` nie istnieją (404 na liście).

- [ ] **Step 6: Utwórz `src/Catalog/UI/Controller/CourseCatalogController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\FindPublishedCourses\FindPublishedCoursesQuery;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CourseCatalogController extends AbstractController
{
    #[Route('/courses', name: 'catalog_list', methods: ['GET'])]
    public function __invoke(QueryBusInterface $queryBus): Response
    {
        return $this->render('catalog/list.html.twig', [
            'courses' => $queryBus->ask(new FindPublishedCoursesQuery()),
        ]);
    }
}
```

- [ ] **Step 7: Utwórz `src/Catalog/UI/Controller/CourseDetailController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class CourseDetailController extends AbstractController
{
    #[Route('/courses/{id}', name: 'catalog_detail', methods: ['GET'])]
    public function __invoke(string $id, QueryBusInterface $queryBus): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $course = $queryBus->ask(new FindPublishedCourseQuery(Uuid::fromString($id)));
        if (!$course instanceof Course) {
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/detail.html.twig', ['course' => $course]);
    }
}
```

- [ ] **Step 8: Utwórz szablony**

`templates/catalog/list.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Katalog kursów{% endblock %}
{% block body %}
    <h1>Katalog kursów</h1>
    <ul>
    {% for course in courses %}
        <li><a href="{{ path('catalog_detail', {id: course.id}) }}">{{ course.title }}</a></li>
    {% else %}
        <li>Brak opublikowanych kursów.</li>
    {% endfor %}
    </ul>
{% endblock %}
```

`templates/catalog/detail.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ course.title }}{% endblock %}
{% block body %}
    <h1>{{ course.title }}</h1>
    <p>{{ course.description }}</p>
    {% for section in course.sections %}
        <h2>{{ section.title }}</h2>
        <ol>
        {% for lesson in section.lessons %}
            <li>{{ lesson.title }}</li>
        {% endfor %}
        </ol>
    {% endfor %}
{% endblock %}
```

- [ ] **Step 9: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/Catalog/CourseBrowsingTest.php`
Expected: PASS (3 testy), pristine.

- [ ] **Step 10: Commit**

```bash
git add src/Catalog/Application/FindPublishedCourses/ src/Catalog/Application/FindPublishedCourse/ src/Catalog/UI/Controller/CourseCatalogController.php src/Catalog/UI/Controller/CourseDetailController.php config/routes.yaml templates/catalog/ tests/Functional/Catalog/CourseBrowsingTest.php
git commit -m "feat(catalog): zapytania + UI przeglądania katalogu i szczegółów kursu"
```

---

### Task 6: UI instruktora (tworzenie/sekcje/lekcje/publikacja) + autoryzacja

**Files:**
- Create: `src/Catalog/Application/FindInstructorCourses/{FindInstructorCoursesQuery.php,FindInstructorCoursesHandler.php}`
- Create: `src/Catalog/UI/Form/{CourseFormData.php,CourseForm.php,SectionFormData.php,SectionForm.php,LessonFormData.php,LessonForm.php}`
- Create: `src/Catalog/UI/Controller/InstructorCourseController.php`
- Modify: `config/packages/security.yaml` (access_control `^/instructor`)
- Create: `templates/instructor/{courses.html.twig,new_course.html.twig,manage_course.html.twig}`
- Create: `tests/Functional/Catalog/InstructorCourseTest.php`

**Interfaces:**
- Consumes: polecenia (Task 3), `QueryBusInterface` + `FindInstructorCoursesQuery`, `CourseRepository`, `User` z Identity (przez `$this->getUser()` → `getId(): Uuid`), wyjątki `NotCourseOwnerException`/`SectionNotFoundException`.
- Produces: trasy pod `/instructor/courses` (lista, new, manage, add-section, add-lesson, publish); `FindInstructorCoursesQuery(Uuid $instructorId)` → `list<Course>`.

- [ ] **Step 1: Utwórz `FindInstructorCoursesQuery.php` + handler**

`src/Catalog/Application/FindInstructorCourses/FindInstructorCoursesQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindInstructorCourses;

use Symfony\Component\Uid\Uuid;

final readonly class FindInstructorCoursesQuery
{
    public function __construct(public Uuid $instructorId)
    {
    }
}
```

`src/Catalog/Application/FindInstructorCourses/FindInstructorCoursesHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindInstructorCourses;

use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindInstructorCoursesHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    /** @return list<\App\Catalog\Domain\Course> */
    public function __invoke(FindInstructorCoursesQuery $query): array
    {
        return $this->courses->ofInstructor($query->instructorId);
    }
}
```

- [ ] **Step 2: Utwórz DTO + FormType dla kursu, sekcji, lekcji**

`src/Catalog/UI/Form/CourseFormData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class CourseFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\NotBlank]
    public ?string $description = null;
}
```

`src/Catalog/UI/Form/CourseForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CourseForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class)->add('description', TextareaType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CourseFormData::class]);
    }
}
```

`src/Catalog/UI/Form/SectionFormData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SectionFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;
}
```

`src/Catalog/UI/Form/SectionForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SectionForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SectionFormData::class]);
    }
}
```

`src/Catalog/UI/Form/LessonFormData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class LessonFormData
{
    #[Assert\NotBlank]
    public ?string $sectionId = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\NotBlank]
    public ?string $content = null;
}
```

`src/Catalog/UI/Form/LessonForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LessonForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sectionId', HiddenType::class)
            ->add('title', TextType::class)
            ->add('content', TextareaType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LessonFormData::class]);
    }
}
```

- [ ] **Step 3: Dodaj autoryzację w `config/packages/security.yaml`**

W `access_control` dodaj regułę dla obszaru instruktora PRZED istniejącą regułą `^/dashboard` (kolejność: pierwsza pasująca wygrywa, ale ścieżki są rozłączne — wystarczy dopisać):

```yaml
        - { path: ^/instructor, roles: ROLE_INSTRUCTOR }
        - { path: ^/dashboard, roles: IS_AUTHENTICATED_FULLY }
```

- [ ] **Step 4: Napisz failujący test `tests/Functional/Catalog/InstructorCourseTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InstructorCourseTest extends WebTestCase
{
    private function loginAs(object $client, string $email, Role $role): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new RegisterUserCommand($email, 'secret123', $role));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail($email);
        $client->loginUser($user);
    }

    public function testStudentCannotAccessInstructorArea(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'student@example.com', Role::Student);

        $client->request('GET', '/instructor/courses');

        self::assertResponseStatusCodeSame(403);
    }

    public function testInstructorCanCreateAddAndPublishCourse(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'instruktor@example.com', Role::Instructor);

        // utwórz kurs
        $client->request('GET', '/instructor/courses/new');
        self::assertResponseIsSuccessful();
        $client->submitForm('Utwórz', [
            'course_form[title]' => 'Mój Kurs',
            'course_form[description]' => 'Opis kursu',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        // strona zarządzania pokazuje kurs
        self::assertSelectorTextContains('body', 'Mój Kurs');

        // dodaj sekcję
        $client->submitForm('Dodaj sekcję', ['section_form[title]' => 'Sekcja 1']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Sekcja 1');

        // dodaj lekcję (sectionId pobierany z ukrytego pola wyrenderowanego w szablonie)
        self::assertSelectorExists('form[name="lesson_form"]');

        // opublikuj — po dodaniu lekcji
        // (publikacja testowana po dodaniu lekcji w UI; sprawdzamy że przycisk publikacji istnieje)
        self::assertSelectorExists('button#publish, form[action*="/publish"] button');
    }
}
```

> Uwaga: ten test sprawdza ścieżkę autoryzacji (student → 403) oraz happy-path tworzenia kursu i dodania sekcji przez instruktora, plus obecność formularzy lekcji/publikacji. Pełny przepływ dodania lekcji i publikacji przez UI jest pokryty na poziomie integracyjnym (Task 4); tutaj weryfikujemy warstwę UI/autoryzacji. Jeśli realny markup różni się od selektorów (`button#publish` itp.), dostosuj selektory do faktycznego szablonu z kroku 7, zachowując sens asercji.

- [ ] **Step 5: Uruchom test — ma FAILOWAĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Catalog/InstructorCourseTest.php`
Expected: FAIL — trasy `/instructor/courses*` nie istnieją.

- [ ] **Step 6: Utwórz `src/Catalog/UI/Controller/InstructorCourseController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\FindInstructorCourses\FindInstructorCoursesQuery;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CannotPublishCourseWithoutLessonsException;
use App\Catalog\UI\Form\CourseForm;
use App\Catalog\UI\Form\CourseFormData;
use App\Catalog\UI\Form\LessonForm;
use App\Catalog\UI\Form\LessonFormData;
use App\Catalog\UI\Form\SectionForm;
use App\Catalog\UI\Form\SectionFormData;
use App\Identity\Domain\User;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/instructor/courses')]
final class InstructorCourseController extends AbstractController
{
    #[Route('', name: 'instructor_courses', methods: ['GET'])]
    public function list(QueryBusInterface $queryBus): Response
    {
        return $this->render('instructor/courses.html.twig', [
            'courses' => $queryBus->ask(new FindInstructorCoursesQuery($this->instructorId())),
        ]);
    }

    #[Route('/new', name: 'instructor_course_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new CourseFormData();
        $form = $this->createForm(CourseForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $courseId = Uuid::v4();
            $commandBus->dispatch(new CreateCourseCommand($courseId, $this->instructorId(), $data->title, $data->description));

            return $this->redirectToRoute('instructor_course_manage', ['id' => (string) $courseId]);
        }

        return $this->render('instructor/new_course.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'instructor_course_manage', methods: ['GET'])]
    public function manage(string $id, CourseRepository $courses): Response
    {
        $course = $this->ownedCourse($id, $courses);

        return $this->render('instructor/manage_course.html.twig', [
            'course' => $course,
            'sectionForm' => $this->createForm(SectionForm::class, new SectionFormData())->createView(),
            'lessonForm' => $this->createForm(LessonForm::class, new LessonFormData())->createView(),
        ]);
    }

    #[Route('/{id}/sections', name: 'instructor_course_add_section', methods: ['POST'])]
    public function addSection(string $id, Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new SectionFormData();
        $form = $this->createForm(SectionForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commandBus->dispatch(new AddSectionCommand(
                $this->uuid($id), Uuid::v4(), $this->instructorId(), $data->title
            ));
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    #[Route('/{id}/lessons', name: 'instructor_course_add_lesson', methods: ['POST'])]
    public function addLesson(string $id, Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new LessonFormData();
        $form = $this->createForm(LessonForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commandBus->dispatch(new AddLessonCommand(
                $this->uuid($id), $this->uuid($data->sectionId), Uuid::v4(), $this->instructorId(), $data->title, $data->content
            ));
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    #[Route('/{id}/publish', name: 'instructor_course_publish', methods: ['POST'])]
    public function publish(string $id, CommandBusInterface $commandBus): Response
    {
        try {
            $commandBus->dispatch(new PublishCourseCommand($this->uuid($id), $this->instructorId()));
            $this->addFlash('success', 'Kurs opublikowany.');
        } catch (CannotPublishCourseWithoutLessonsException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    private function instructorId(): Uuid
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user->getId();
    }

    private function uuid(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return Uuid::fromString($id);
    }

    private function ownedCourse(string $id, CourseRepository $courses): Course
    {
        $course = $courses->ofId($this->uuid($id));
        if (null === $course || !$course->belongsTo($this->instructorId())) {
            throw $this->createNotFoundException();
        }

        return $course;
    }
}
```

- [ ] **Step 7: Utwórz szablony instruktora**

`templates/instructor/courses.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Moje kursy{% endblock %}
{% block body %}
    <h1>Moje kursy</h1>
    <a href="{{ path('instructor_course_new') }}">Nowy kurs</a>
    <ul>
    {% for course in courses %}
        <li><a href="{{ path('instructor_course_manage', {id: course.id}) }}">{{ course.title }}</a> — {{ course.status.value }}</li>
    {% else %}
        <li>Brak kursów.</li>
    {% endfor %}
    </ul>
{% endblock %}
```

`templates/instructor/new_course.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Nowy kurs{% endblock %}
{% block body %}
    <h1>Nowy kurs</h1>
    {{ form_start(form) }}
        {{ form_row(form.title) }}
        {{ form_row(form.description) }}
        <button type="submit">Utwórz</button>
    {{ form_end(form) }}
{% endblock %}
```

`templates/instructor/manage_course.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ course.title }}{% endblock %}
{% block body %}
    <h1>{{ course.title }} ({{ course.status.value }})</h1>

    {% for label, messages in app.flashes %}
        {% for message in messages %}<div class="alert {{ label }}">{{ message }}</div>{% endfor %}
    {% endfor %}

    {% for section in course.sections %}
        <h2>{{ section.title }}</h2>
        <ol>{% for lesson in section.lessons %}<li>{{ lesson.title }}</li>{% endfor %}</ol>
    {% endfor %}

    <h3>Dodaj sekcję</h3>
    <form method="post" action="{{ path('instructor_course_add_section', {id: course.id}) }}">
        {{ form_widget(sectionForm) }}
        <button type="submit">Dodaj sekcję</button>
    </form>

    <h3>Dodaj lekcję</h3>
    <form method="post" action="{{ path('instructor_course_add_lesson', {id: course.id}) }}">
        {{ form_widget(lessonForm) }}
        <button type="submit">Dodaj lekcję</button>
    </form>

    <form method="post" action="{{ path('instructor_course_publish', {id: course.id}) }}">
        <button type="submit" id="publish">Opublikuj kurs</button>
    </form>
{% endblock %}
```

> Pole `sectionId` w formularzu lekcji jest ukryte; w realnym UI instruktor wybiera sekcję — dla MVP wystarczy ukryte pole wypełniane np. wartością istniejącej sekcji (rozszerzenie: lista wyboru sekcji). Jeśli funkcjonalny test dodawania lekcji ma przejść end-to-end, wypełnij `lesson_form[sectionId]` realnym id sekcji w teście.

- [ ] **Step 8: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/Catalog/InstructorCourseTest.php`
Expected: PASS, pristine. Dostosuj selektory/etykiety, jeśli realny markup się różni (zachowując sens asercji: student→403, instruktor tworzy kurs i sekcję).

- [ ] **Step 9: Pełny zestaw (regresja całej fazy + poprzednich)**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: wszystko zielone, pristine.

- [ ] **Step 10: Commit**

```bash
git add src/Catalog/Application/FindInstructorCourses/ src/Catalog/UI/ config/packages/security.yaml templates/instructor/ tests/Functional/Catalog/InstructorCourseTest.php
git commit -m "feat(catalog): UI instruktora (tworzenie kursu, sekcje, lekcje, publikacja) + autoryzacja ROLE_INSTRUCTOR"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu:**
- Instruktor tworzy kurs z sekcjami i lekcjami → Tasks 2-4 (domena/aplikacja/infra) + Task 6 (UI) ✅
- Publikacja kursu (DRAFT→PUBLISHED) → Task 2 (publish + niezmiennik), Task 3 (handler), Task 6 (UI) ✅
- Student przegląda katalog opublikowanych i szczegóły → Task 5 ✅
- Model Course/Module(=Section)/Lesson, status → Task 2 ✅
- Warstwy + porty (CourseRepository), CQRS-lite (command.bus + query.bus) → Tasks 1,3,4,5 ✅
- Granice modułów: brak współdzielenia encji; `instructorId: Uuid` → Task 2/3/6 ✅
- Wyjątki domenowe → 4xx/feedback (CourseNotFound→404, NotCourseOwner→404, brak lekcji→flash) → Tasks 3,6 ✅
- Autoryzacja role (ROLE_INSTRUCTOR + własność) → Task 6 ✅
- Testy: unit (Task 1,2,3), integration (Task 4), functional (Task 5,6) — piramida §6 ✅

**Placeholdery:** brak „TBD/TODO" w krokach; migracje generowane (`migrations:diff`) — celowe, z opisem oczekiwanego SQL.

**Spójność typów:** `CourseRepository` (`save/ofId/allPublished/ofInstructor`) spójne w Tasks 3-6. Polecenia i ich pola (Uuid + string) identyczne między definicją (Task 3) a użyciem (Task 4 integracja, Tasks 5-6 dispatch). `Course`/`Section`/`Lesson` gettery spójne między Task 2 a szablonami (Task 5/6). `QueryBusInterface::ask` spójne (Task 1 → Tasks 5-6).

**Świadome decyzje:**
- Agregat `Course` (korzeń) z kaskadą — niezmienniki w jednym miejscu; Sections/Lessons bez własnych repozytoriów (dostęp przez korzeń) — czyste DDD.
- `Section` zamiast „Module" — unika kolizji z „moduł = bounded context".
- Strona zapytań zwraca encje `Course` do szablonów (pragmatyczne dla server-side Twig w monolicie); read-modele DTO to ewentualne przyszłe rozszerzenie.
- `instructorId: Uuid` bez relacji ORM do `User` — twarda granica modułu; gotowe pod integration eventy Fazy 4.
- Tworzenie kursu publiczne dla zalogowanych instruktorów; przeglądanie katalogu publiczne (anonim); zapis na kurs (logowanie wymagane) dochodzi w Fazie 4.

**Uwagi wykonawcze:**
- Migracja tworzy 3 tabele z FK; sprawdzić kolejność DROP w `down()` (lessons→sections→courses).
- Test instruktora (Task 6) skupia się na autoryzacji + happy-path tworzenia; pełny przepływ dodania lekcji/publikacji przez UI można wzmocnić, wypełniając `lesson_form[sectionId]` realnym id (selektory dostosować do realnego markupu).
- `client->loginUser()` (Task 6) wymaga, by provider bezpieczeństwa (entity, z Fazy 2) działał — działa, bo `User` jest w tym samym kontenerze testowym.
```
