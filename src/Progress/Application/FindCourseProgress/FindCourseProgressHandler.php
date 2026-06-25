<?php

declare(strict_types=1);

namespace App\Progress\Application\FindCourseProgress;

use App\Progress\Domain\ProgressRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindCourseProgressHandler
{
    public function __construct(private ProgressRepository $progress) {}

    public function __invoke(FindCourseProgressQuery $query): ?CourseProgressView
    {
        $progress = $this->progress->ofUserAndCourse($query->userId, $query->courseId);
        if (null === $progress) {
            return null;
        }

        return new CourseProgressView(
            $progress->completionPercentage(),
            $progress->completedCount(),
            $progress->getTotalLessons(),
            $progress->isCompleted(),
        );
    }
}
