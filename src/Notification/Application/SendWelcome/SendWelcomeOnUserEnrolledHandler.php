<?php

declare(strict_types=1);

namespace App\Notification\Application\SendWelcome;

use App\Enrollment\Domain\Event\UserEnrolled;
use App\Notification\Application\Mailer;
use App\Notification\Application\RecipientResolver;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'notification_enrollment_in')]
final readonly class SendWelcomeOnUserEnrolledHandler
{
    public function __construct(
        private SentNotificationRepository $sent,
        private RecipientResolver $recipients,
        private Mailer $mailer,
    ) {
    }

    public function __invoke(UserEnrolled $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->sent->alreadySent(NotificationType::Welcome, $userId, $courseId)) {
            return;
        }
        $email = $this->recipients->emailFor($userId);
        if (null === $email) {
            return;
        }

        // send -> save order is intentional (at-least-once): we prefer a potential
        // duplicate email over losing it. Idempotency is guaranteed by the DB unique
        // constraint on (type, user, course) checked by alreadySent().
        $this->mailer->send($email, 'Welcome to the course!', 'You have been enrolled in the course. Good luck with your studies!');
        $this->sent->save(new SentNotification(NotificationType::Welcome, $userId, $courseId));
    }
}
