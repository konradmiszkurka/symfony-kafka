<?php

declare(strict_types=1);

namespace App\Enrollment\Application\EnrollStudent;

use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class EnrollStudentHandler
{
    public function __construct(
        private CourseAvailabilityChecker $courses,
        private EnrollmentRepository $enrollments,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(EnrollStudentCommand $command): void
    {
        if (!$this->courses->isEnrollable($command->courseId)) {
            throw CourseNotEnrollableException::create();
        }
        if ($this->enrollments->exists($command->userId, $command->courseId)) {
            throw AlreadyEnrolledException::create();
        }

        $occurredAt = new \DateTimeImmutable();
        $this->enrollments->save(
            Enrollment::enroll(Uuid::v4(), $command->userId, $command->courseId, $occurredAt)
        );

        $this->eventBus->publish(new UserEnrolled(
            (string) $command->userId,
            (string) $command->courseId,
            $occurredAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
