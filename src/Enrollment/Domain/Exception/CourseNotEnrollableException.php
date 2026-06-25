<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Exception;

final class CourseNotEnrollableException extends \DomainException
{
    public static function create(): self
    {
        return new self('This course is not open for enrollment.');
    }
}
