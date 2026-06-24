<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Symfony\Component\Uid\Uuid;

interface SentNotificationRepository
{
    public function alreadySent(NotificationType $type, Uuid $userId, Uuid $courseId): bool;

    public function save(SentNotification $notification): void;
}
