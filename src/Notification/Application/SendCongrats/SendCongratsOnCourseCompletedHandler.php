<?php

declare(strict_types=1);

namespace App\Notification\Application\SendCongrats;

use App\Notification\Application\Mailer;
use App\Notification\Application\RecipientResolver;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use App\Progress\Domain\Event\CourseCompleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'notification_progress_in')]
final readonly class SendCongratsOnCourseCompletedHandler
{
    public function __construct(
        private SentNotificationRepository $sent,
        private RecipientResolver $recipients,
        private Mailer $mailer,
    ) {
    }

    public function __invoke(CourseCompleted $event): void
    {
        $userId = Uuid::fromString($event->userId);
        $courseId = Uuid::fromString($event->courseId);

        if ($this->sent->alreadySent(NotificationType::CourseCompleted, $userId, $courseId)) {
            return;
        }
        $email = $this->recipients->emailFor($userId);
        if (null === $email) {
            return;
        }

        // Kolejność send -> save jest świadoma (at-least-once): wolimy ewentualny
        // duplikat maila niż jego utratę. Idempotencję gwarantuje unikat w DB
        // (type, user, course) sprawdzany przez alreadySent().
        $this->mailer->send($email, 'Gratulacje — kurs ukończony!', 'Ukończyłeś cały kurs. Świetna robota!');
        $this->sent->save(new SentNotification(NotificationType::CourseCompleted, $userId, $courseId));
    }
}
