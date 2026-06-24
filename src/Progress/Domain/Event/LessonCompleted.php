<?php

declare(strict_types=1);

namespace App\Progress\Domain\Event;

final readonly class LessonCompleted
{
    public function __construct(
        public string $userId,
        public string $courseId,
        public string $lessonId,
        public string $occurredAt,
    ) {
    }
}
