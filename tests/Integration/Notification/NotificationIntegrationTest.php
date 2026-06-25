<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Notification\Application\SendWelcome\SendWelcomeOnUserEnrolledHandler;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationIntegrationTest extends KernelTestCase
{
    public function testWelcomeMailSentOnceAndIsIdempotent(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        $courseId = Uuid::v4();
        $event = new UserEnrolled((string) $user->getId(), (string) $courseId, '2026-06-24T10:00:00+00:00');

        $handler = self::getContainer()->get(SendWelcomeOnUserEnrolledHandler::class);
        $handler($event);
        $handler($event); // dedupe — second call does not send

        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessages()[0], 'From', 'platforma@example.com');
    }
}
