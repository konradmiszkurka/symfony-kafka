<?php

declare(strict_types=1);

namespace App\Enrollment\Application\EnrollStudent;

use Symfony\Component\Uid\Uuid;

final readonly class EnrollStudentCommand
{
    public function __construct(
        public Uuid $userId,
        public Uuid $courseId,
    ) {
    }
}
