<?php

declare(strict_types=1);

namespace App\Progress\Domain;

use Symfony\Component\Uid\Uuid;

interface ProgressRepository
{
    public function save(CourseProgress $progress): void;

    public function ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress;

    public function exists(Uuid $userId, Uuid $courseId): bool;
}
