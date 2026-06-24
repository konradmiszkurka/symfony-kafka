<?php

declare(strict_types=1);

namespace App\Tests\Integration\Enrollment;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\EnrollmentRepository;
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

        // Persystencja: zapis trafił do bazy.
        $enrollments = self::getContainer()->get(EnrollmentRepository::class);
        self::assertTrue($enrollments->exists($userId, $courseId));

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
