<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

final class EmailAlreadyInUseException extends \DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('Email "%s" is already in use.', $email));
    }
}
