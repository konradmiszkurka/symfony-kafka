<?php

declare(strict_types=1);

namespace App\Identity\Application\RegisterUser;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): void
    {
        if ($this->users->existsByEmail($command->email)) {
            throw EmailAlreadyInUseException::forEmail($command->email);
        }

        $user = User::register(
            $command->email,
            $this->passwordHasher->hash($command->plainPassword),
            $command->role,
        );

        $this->users->save($user);
    }
}
