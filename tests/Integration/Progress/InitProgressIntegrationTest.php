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
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Course', 'Description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L1', 't'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L2', 't'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $handler = self::getContainer()->get(InitProgressOnUserEnrolledHandler::class);
        $userId = Uuid::v4();
        $event = new UserEnrolled((string) $userId, (string) $courseId, '2026-06-24T10:00:00+00:00');

        $handler($event);
        $handler($event); // idempotent

        $repo = self::getContainer()->get(ProgressRepository::class);
        $progress = $repo->ofUserAndCourse($userId, $courseId);
        self::assertNotNull($progress);
        self::assertSame(2, $progress->getTotalLessons());
    }
}
