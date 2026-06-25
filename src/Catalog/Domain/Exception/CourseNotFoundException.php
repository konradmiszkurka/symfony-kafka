<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class CourseNotFoundException extends \DomainException
{
    public static function withId(string $courseId): self
    {
        return new self(sprintf('Course "%s" does not exist.', $courseId));
    }
}
