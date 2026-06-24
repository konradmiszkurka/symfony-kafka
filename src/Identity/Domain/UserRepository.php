<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface UserRepository
{
    public function save(User $user): void;

    public function ofEmail(string $email): ?User;

    public function existsByEmail(string $email): bool;
}
