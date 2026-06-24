<?php

declare(strict_types=1);

namespace App\Progress\Domain\Exception;

final class ProgressNotFoundException extends \DomainException
{
    public static function create(): self
    {
        return new self('Brak rozpoczętego postępu dla tego kursu.');
    }
}
