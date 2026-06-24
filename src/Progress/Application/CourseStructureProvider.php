<?php

declare(strict_types=1);

namespace App\Progress\Application;

use Symfony\Component\Uid\Uuid;

interface CourseStructureProvider
{
    /** @return list<string> identyfikatory lekcji kursu */
    public function lessonIds(Uuid $courseId): array;
}
