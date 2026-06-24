<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface UserRepository
{
    public function save(User $user): void;

    public function ofEmail(string $email): ?User;

    public function existsByEmail(string $email): bool;

    public function ofId(Uuid $id): ?User;
}
