<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

final class SectionNotFoundException extends \DomainException
{
    public static function withId(string $sectionId): self
    {
        return new self(sprintf('Sekcja "%s" nie istnieje w tym kursie.', $sectionId));
    }
}
