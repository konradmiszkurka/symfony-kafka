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
