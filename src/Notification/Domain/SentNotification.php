<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'sent_notifications')]
#[ORM\UniqueConstraint(name: 'uniq_notification', columns: ['type', 'user_id', 'course_id'])]
final class SentNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $courseId;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(NotificationType $type, Uuid $userId, Uuid $courseId)
    {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->sentAt = new \DateTimeImmutable();
    }
}
