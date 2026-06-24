<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Identity;

use App\Identity\Domain\UserRepository;
use App\Notification\Application\RecipientResolver;
use Symfony\Component\Uid\Uuid;

final readonly class IdentityRecipientResolver implements RecipientResolver
{
    public function __construct(private UserRepository $users)
    {
    }

    public function emailFor(Uuid $userId): ?string
    {
        return $this->users->ofId($userId)?->getEmail();
    }
}
