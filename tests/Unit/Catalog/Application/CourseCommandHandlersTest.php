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

        (new CreateCourseHandler($repo))(new CreateCourseCommand($courseId, $instructor, 'Course', 'Description'));
        (new AddSectionHandler($repo))(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        (new AddLessonHandler($repo))(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lesson', 'content'));
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
        (new CreateCourseHandler($repo))(new CreateCourseCommand($courseId, $owner, 'Course', 'Description'));

        $this->expectException(NotCourseOwnerException::class);
        (new AddSectionHandler($repo))(new AddSectionCommand($courseId, Uuid::v4(), Uuid::v4(), 'Section'));
    }

    public function testPublishMissingCourseThrows(): void
    {
        $repo = $this->repo();

        $this->expectException(CourseNotFoundException::class);
        (new PublishCourseHandler($repo))(new PublishCourseCommand(Uuid::v4(), Uuid::v4()));
    }
}
