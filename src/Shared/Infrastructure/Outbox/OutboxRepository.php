<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxRepository
{
    /** Persist bez flush — commit robi transakcja owijająca handler. */
    public function add(OutboxMessage $message): void;

    /** Persist + flush — używane przez relay przy oznaczaniu jako wysłane. */
    public function save(OutboxMessage $message): void;

    /** @return list<OutboxMessage> */
    public function unsent(int $limit): array;

    /** Liczba wierszy bez sentAt (jeszcze niewysłanych). */
    public function countUnsent(): int;

    /** Liczba wierszy bez sentAt, których createdAt < $olderThan (zalegające). */
    public function countStuck(\DateTimeImmutable $olderThan): int;
}
