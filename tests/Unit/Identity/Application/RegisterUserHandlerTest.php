<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\PasswordHasher;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Application\RegisterUser\RegisterUserHandler;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use PHPUnit\Framework\TestCase;

final class RegisterUserHandlerTest extends TestCase
{
    public function testRegistersUserWithHashedPassword(): void
    {
        $repo = new class implements UserRepository {
            public array $saved = [];
            public function save(User $user): void { $this->saved[] = $user; }
            public function ofEmail(string $email): ?User { return null; }
            public function existsByEmail(string $email): bool { return false; }
        };
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string { return 'hashed:'.$plainPassword; }
        };
        $handler = new RegisterUserHandler($repo, $hasher);

        $handler(new RegisterUserCommand('jan@example.com', 'secret123', Role::Student));

        self::assertCount(1, $repo->saved);
        $user = $repo->saved[0];
        self::assertSame('jan@example.com', $user->getEmail());
        self::assertSame('hashed:secret123', $user->getPassword());
        self::assertContains('ROLE_STUDENT', $user->getRoles());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $repo = new class implements UserRepository {
            public function save(User $user): void {}
            public function ofEmail(string $email): ?User { return null; }
            public function existsByEmail(string $email): bool { return true; }
        };
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string { return 'x'; }
        };
        $handler = new RegisterUserHandler($repo, $hasher);

        $this->expectException(EmailAlreadyInUseException::class);
        $handler(new RegisterUserCommand('taken@example.com', 'secret123', Role::Instructor));
    }
}
