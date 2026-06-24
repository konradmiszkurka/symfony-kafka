<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use Symfony\Component\Uid\Uuid;

interface EnrollmentRepository
{
    public function save(Enrollment $enrollment): void;

    public function exists(Uuid $userId, Uuid $courseId): bool;
}
