<?php

declare(strict_types=1);

namespace App\Progress\Application\FindCourseProgress;

use Symfony\Component\Uid\Uuid;

final readonly class FindCourseProgressQuery
{
    public function __construct(
        public Uuid $userId,
        public Uuid $courseId,
    ) {}
}
