<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegisterUserIntegrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testRegisterPersistsUser(): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $users = self::getContainer()->get(UserRepository::class);

        $bus->dispatch(new RegisterUserCommand('nowy@example.com', 'secret123', Role::Student));

        $user = $users->ofEmail('nowy@example.com');
        self::assertNotNull($user);
        self::assertSame('nowy@example.com', $user->getEmail());
        self::assertContains('ROLE_STUDENT', $user->getRoles());
        self::assertNotSame('secret123', $user->getPassword(), 'password must be hashed');
    }

    public function testDuplicateEmailThrows(): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);

        $bus->dispatch(new RegisterUserCommand('dup@example.com', 'secret123', Role::Student));

        $this->expectException(EmailAlreadyInUseException::class);
        $bus->dispatch(new RegisterUserCommand('dup@example.com', 'inne123', Role::Instructor));
    }
}
