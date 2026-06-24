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
