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
