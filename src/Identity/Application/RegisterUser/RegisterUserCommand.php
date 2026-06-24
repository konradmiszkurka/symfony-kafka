<?php

declare(strict_types=1);

namespace App\Identity\Application\RegisterUser;

use App\Identity\Domain\Role;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public Role $role,
    ) {
    }
}
