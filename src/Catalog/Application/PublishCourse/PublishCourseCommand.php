<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishCourse;

use Symfony\Component\Uid\Uuid;

final readonly class PublishCourseCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $instructorId,
    ) {
    }
}
