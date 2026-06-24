<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testUserCanRegister(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $client->submitForm('Zarejestruj', [
            'registration_form[email]' => 'rejestracja@example.com',
            'registration_form[plainPassword]' => 'secret123',
            'registration_form[role]' => 'ROLE_STUDENT',
        ]);

        self::assertResponseRedirects('/login');

        $users = self::getContainer()->get(UserRepository::class);
        self::assertNotNull($users->ofEmail('rejestracja@example.com'));
    }

    public function testDuplicateEmailShowsError(): void
    {
        $client = static::createClient();

        $register = static function () use ($client): void {
            $client->request('GET', '/register');
            $client->submitForm('Zarejestruj', [
                'registration_form[email]' => 'duplikat@example.com',
                'registration_form[plainPassword]' => 'secret123',
                'registration_form[role]' => 'ROLE_STUDENT',
            ]);
        };

        $register();
        $register();

        self::assertSelectorTextContains('body', 'zajęty');
    }
}
