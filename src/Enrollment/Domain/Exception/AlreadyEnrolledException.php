<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Exception;

final class AlreadyEnrolledException extends \DomainException
{
    public static function create(): self
    {
        return new self('You are already enrolled in this course.');
    }
}
