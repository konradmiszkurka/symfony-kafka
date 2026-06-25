<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class OutboxRelayTest extends TestCase
{
    public function testRelayDispatchesEventAndMarksSent(): void
    {
        $message = OutboxMessage::create(\stdClass::class, '{}');

        $repo = new class($message) implements OutboxRepository {
            public array $saved = [];
            public function __construct(private OutboxMessage $m) {}
            public function add(OutboxMessage $message): void {}
            public function save(OutboxMessage $message): void { $this->saved[] = $message; }
            public function unsent(int $limit): array { return null === $this->m->getSentAt() ? [$this->m] : []; }
        };

        $event = new \stdClass();
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn($event);

        $bus = new class implements MessageBusInterface {
            public array $dispatched = [];
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;
                return new Envelope($message);
            }
        };

        $relay = new OutboxRelay($repo, $bus, $serializer, new NullLogger());

        $count = $relay->relayBatch(10);

        self::assertSame(1, $count);
        self::assertSame([$event], $bus->dispatched);
        self::assertNotNull($message->getSentAt());
        self::assertSame([$message], $repo->saved);
    }

    public function testRelayLogsAndContinuesOnDispatchFailureWithoutMarkingSent(): void
    {
        $message = OutboxMessage::create(\stdClass::class, '{}');

        $repo = new class($message) implements OutboxRepository {
            public array $saved = [];
            public function __construct(private OutboxMessage $m) {}
            public function add(OutboxMessage $message): void {}
            public function save(OutboxMessage $message): void { $this->saved[] = $message; }
            public function unsent(int $limit): array { return [$this->m]; }
        };

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('deserialize')->willReturn(new \stdClass());

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('broker down');
            }
        };

        $relay = new OutboxRelay($repo, $bus, $serializer, new NullLogger());

        $count = $relay->relayBatch(10);

        // Ścieżka błędu: nie liczy się, nie oznacza wysłanego, nie zapisuje → wiersz zostanie ponowiony.
        self::assertSame(0, $count);
        self::assertNull($message->getSentAt());
        self::assertSame([], $repo->saved);
    }
}
