<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class CannotPublishCourseWithoutLessonsException extends \DomainException
{
    public static function create(): self
    {
        return new self('Nie można opublikować kursu bez żadnej lekcji.');
    }
}
