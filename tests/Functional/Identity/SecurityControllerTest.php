<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testProtectedPageRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        self::getContainer()->get(CommandBusInterface::class)
            ->dispatch(new RegisterUserCommand('login@example.com', 'secret123', Role::Student));

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'login@example.com',
            '_password' => 'secret123',
        ]);

        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'login@example.com');
    }

    public function testLoginWithInvalidCredentialsShowsError(): void
    {
        $client = static::createClient();
        self::getContainer()->get(CommandBusInterface::class)
            ->dispatch(new RegisterUserCommand('zly@example.com', 'secret123', Role::Student));

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'zly@example.com',
            '_password' => 'bledne-haslo',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('.alert, .error');
    }
}
