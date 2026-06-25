<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxRepository
{
    /** Persist without flush — the transaction wrapping the handler commits. */
    public function add(OutboxMessage $message): void;

    /** Persist + flush — used by the relay when marking messages as sent. */
    public function save(OutboxMessage $message): void;

    /** @return list<OutboxMessage> */
    public function unsent(int $limit): array;

    /** Number of rows without sentAt (not yet sent). */
    public function countUnsent(): int;

    /** Number of rows without sentAt whose createdAt < $olderThan (stuck). */
    public function countStuck(\DateTimeImmutable $olderThan): int;
}
