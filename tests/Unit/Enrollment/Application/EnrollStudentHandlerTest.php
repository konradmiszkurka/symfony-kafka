<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enrollment\Application;

use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentHandler;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\EventBusInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollStudentHandlerTest extends TestCase
{
    private function checker(bool $enrollable): CourseAvailabilityChecker
    {
        return new class($enrollable) implements CourseAvailabilityChecker {
            public function __construct(private bool $enrollable) {}
            public function isEnrollable(Uuid $courseId): bool { return $this->enrollable; }
        };
    }

    private function repo(bool $exists): EnrollmentRepository
    {
        return new class($exists) implements EnrollmentRepository {
            public array $saved = [];
            public function __construct(private bool $exists) {}
            public function save(Enrollment $enrollment): void { $this->saved[] = $enrollment; }
            public function exists(Uuid $userId, Uuid $courseId): bool { return $this->exists; }
        };
    }

    private function eventBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            public array $published = [];
            public function publish(object $event): void { $this->published[] = $event; }
        };
    }

    public function testEnrollsAndPublishesEvent(): void
    {
        $repo = $this->repo(false);
        $bus = $this->eventBus();
        $handler = new EnrollStudentHandler($this->checker(true), $repo, $bus);

        $userId = Uuid::v4();
        $courseId = Uuid::v4();
        $handler(new EnrollStudentCommand($userId, $courseId));

        self::assertCount(1, $repo->saved);
        self::assertCount(1, $bus->published);
        self::assertInstanceOf(UserEnrolled::class, $bus->published[0]);
        self::assertSame((string) $userId, $bus->published[0]->userId);
        self::assertSame((string) $courseId, $bus->published[0]->courseId);
    }

    public function testRejectsNonEnrollableCourse(): void
    {
        $handler = new EnrollStudentHandler($this->checker(false), $this->repo(false), $this->eventBus());

        $this->expectException(CourseNotEnrollableException::class);
        $handler(new EnrollStudentCommand(Uuid::v4(), Uuid::v4()));
    }

    public function testRejectsDuplicateEnrollment(): void
    {
        $handler = new EnrollStudentHandler($this->checker(true), $this->repo(true), $this->eventBus());

        $this->expectException(AlreadyEnrolledException::class);
        $handler(new EnrollStudentCommand(Uuid::v4(), Uuid::v4()));
    }
}
