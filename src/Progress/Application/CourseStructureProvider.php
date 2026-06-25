<?php

declare(strict_types=1);

namespace App\Progress\Application;

use Symfony\Component\Uid\Uuid;

interface CourseStructureProvider
{
    /** @return list<string> the course's lesson ids */
    public function lessonIds(Uuid $courseId): array;
}
