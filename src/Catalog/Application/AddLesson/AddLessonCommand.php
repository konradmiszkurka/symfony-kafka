<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddLesson;

use Symfony\Component\Uid\Uuid;

final readonly class AddLessonCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $sectionId,
        public Uuid $lessonId,
        public Uuid $instructorId,
        public string $title,
        public string $content,
    ) {
    }
}
