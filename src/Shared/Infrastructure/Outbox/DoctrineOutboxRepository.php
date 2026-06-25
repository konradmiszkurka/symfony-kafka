<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineOutboxRepository implements OutboxRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(OutboxMessage $message): void
    {
        $this->em->persist($message);
    }

    public function save(OutboxMessage $message): void
    {
        $this->em->persist($message);
        $this->em->flush();
    }

    public function unsent(int $limit): array
    {
        return array_values(
            $this->em->getRepository(OutboxMessage::class)
                ->findBy(['sentAt' => null], ['createdAt' => 'ASC'], $limit)
        );
    }

    public function countUnsent(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . OutboxMessage::class . ' m WHERE m.sentAt IS NULL'
        )->getSingleScalarResult();
    }

    public function countStuck(\DateTimeImmutable $olderThan): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . OutboxMessage::class . ' m WHERE m.sentAt IS NULL AND m.createdAt < :t'
        )->setParameter('t', $olderThan)->getSingleScalarResult();
    }
}
