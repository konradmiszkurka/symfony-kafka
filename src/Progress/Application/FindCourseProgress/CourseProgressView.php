<?php

declare(strict_types=1);

namespace App\Progress\Application\FindCourseProgress;

final readonly class CourseProgressView
{
    public function __construct(
        public int $percentage,
        public int $completedLessons,
        public int $totalLessons,
        public bool $completed,
    ) {}
}
