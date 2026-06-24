<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use Symfony\Component\Uid\Uuid;

interface CourseAvailabilityChecker
{
    public function isEnrollable(Uuid $courseId): bool;
}
