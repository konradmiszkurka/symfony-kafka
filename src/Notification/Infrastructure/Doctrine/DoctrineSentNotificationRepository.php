<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SentNotification;
use App\Notification\Domain\SentNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSentNotificationRepository implements SentNotificationRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function alreadySent(NotificationType $type, Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(SentNotification::class)
            ->count(['type' => $type, 'userId' => $userId, 'courseId' => $courseId]) > 0;
    }

    public function save(SentNotification $notification): void
    {
        $this->em->persist($notification);
        $this->em->flush();
    }
}
