<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox')]
#[ORM\Index(name: 'idx_outbox_sent_at', columns: ['sent_at'])]
final class OutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $messageType;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt;

    private function __construct(Uuid $id, string $messageType, string $payload, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->messageType = $messageType;
        $this->payload = $payload;
        $this->createdAt = $createdAt;
        $this->sentAt = null;
    }

    public static function create(string $messageType, string $payload): self
    {
        return new self(Uuid::v4(), $messageType, $payload, new \DateTimeImmutable());
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
}
