<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OutboxMessageTest extends TestCase
{
    public function testCreateIsUnsent(): void
    {
        $m = OutboxMessage::create('App\\Some\\Event', '{"a":1}');

        self::assertInstanceOf(Uuid::class, $m->getId());
        self::assertSame('App\\Some\\Event', $m->getMessageType());
        self::assertSame('{"a":1}', $m->getPayload());
        self::assertNull($m->getSentAt());
    }

    public function testMarkSent(): void
    {
        $m = OutboxMessage::create('App\\Some\\Event', '{}');
        $at = new \DateTimeImmutable('2026-06-25T10:00:00+00:00');

        $m->markSent($at);

        self::assertSame($at, $m->getSentAt());
    }
}
