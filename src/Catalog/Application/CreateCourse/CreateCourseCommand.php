<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

use Symfony\Component\Uid\Uuid;

final readonly class CreateCourseCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $instructorId,
        public string $title,
        public string $description,
    ) {
    }
}
