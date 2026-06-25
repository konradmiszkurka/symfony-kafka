<?php

declare(strict_types=1);

namespace App\Progress\Application\InitProgress;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Progress\Application\CourseStructureProvider;
use App\Progress\Domain\CourseProgress;
use App\Progress\Domain\ProgressRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'progress_enrollment_in')]
final readonly class InitProgressOnUserEnrolledHandler
{
    public function __construct(
        private ProgressRepository $progress,
        private CourseStructureProvider $courses,
    ) {
    }

    public function __invoke(UserEnrolled $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->progress->exists($userId, $courseId)) {
            return; // idempotent — progress already initialised
        }

        $totalLessons = \count($this->courses->lessonIds($courseId));
        $this->progress->save(CourseProgress::start(Uuid::v4(), $userId, $courseId, $totalLessons));
    }
}
