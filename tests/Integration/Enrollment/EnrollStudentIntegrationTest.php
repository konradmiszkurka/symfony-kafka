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
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollStudentIntegrationTest extends KernelTestCase
{
    private function publishCourse(CommandBusInterface $bus): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Course', 'Description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lesson', 'content'));
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

        // Persistence: record was saved to the database.
        $enrollments = self::getContainer()->get(EnrollmentRepository::class);
        self::assertTrue($enrollments->exists($userId, $courseId));

        $unsent = self::getContainer()->get(OutboxRepository::class)->unsent(100);
        $match = array_values(array_filter($unsent, static fn ($m) => UserEnrolled::class === $m->getMessageType()));
        self::assertNotEmpty($match, 'No UserEnrolled event in the outbox.');
        $payload = json_decode($match[0]->getPayload(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((string) $userId, $payload['userId']);
        self::assertSame((string) $courseId, $payload['courseId']);
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
        $bus->dispatch(new CreateCourseCommand($draftId, Uuid::v4(), 'Draft', 'Description'));

        $this->expectException(CourseNotEnrollableException::class);
        $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $draftId));
    }
}
