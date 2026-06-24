<?php

declare(strict_types=1);

namespace App\Progress\Application\MarkLessonCompleted;

use Symfony\Component\Uid\Uuid;

final readonly class MarkLessonCompletedCommand
{
    public function __construct(
        public Uuid $userId,
        public Uuid $courseId,
        public Uuid $lessonId,
    ) {
    }
}
