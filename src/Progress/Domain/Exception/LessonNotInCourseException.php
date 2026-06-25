<?php

declare(strict_types=1);

namespace App\Progress\Domain\Exception;

final class LessonNotInCourseException extends \DomainException
{
    public static function create(): self
    {
        return new self('Lesson does not belong to this course.');
    }
}
