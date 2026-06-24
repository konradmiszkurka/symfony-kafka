<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserTest extends TestCase
{
    public function testRegisterCreatesUserWithUuidAndRole(): void
    {
        $user = User::register('jan@example.com', 'hashed-secret', Role::Student);

        self::assertInstanceOf(Uuid::class, $user->getId());
        self::assertSame('jan@example.com', $user->getEmail());
        self::assertSame('jan@example.com', $user->getUserIdentifier());
        self::assertSame('hashed-secret', $user->getPassword());
    }

    public function testRolesAlwaysIncludeRoleUser(): void
    {
        $student = User::register('s@example.com', 'h', Role::Student);
        $instructor = User::register('i@example.com', 'h', Role::Instructor);

        self::assertEqualsCanonicalizing(['ROLE_STUDENT', 'ROLE_USER'], $student->getRoles());
        self::assertEqualsCanonicalizing(['ROLE_INSTRUCTOR', 'ROLE_USER'], $instructor->getRoles());
    }
}
