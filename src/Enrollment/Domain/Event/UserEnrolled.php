<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Event;

final readonly class UserEnrolled
{
    public function __construct(
        public string $userId,
        public string $courseId,
        public string $occurredAt,
    ) {
    }
}
