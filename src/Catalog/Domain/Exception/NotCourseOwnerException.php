<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class NotCourseOwnerException extends \DomainException
{
    public static function create(): self
    {
        return new self('Nie jesteś właścicielem tego kursu.');
    }
}
