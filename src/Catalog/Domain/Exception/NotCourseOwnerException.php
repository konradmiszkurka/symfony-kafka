<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class NotCourseOwnerException extends \DomainException
{
    public static function create(): self
    {
        return new self('You are not the owner of this course.');
    }
}
