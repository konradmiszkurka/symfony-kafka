<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Bus\MessengerQueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBusTest extends TestCase
{
    public function testReturnsHandlerResult(): void
    {
        $query = new \stdClass();
        $inner = $this->createStub(MessageBusInterface::class);
        $inner->method('dispatch')->willReturn(
            (new Envelope($query))->with(new HandledStamp('wynik', 'handler'))
        );

        $bus = new MessengerQueryBus($inner);

        self::assertSame('wynik', $bus->ask($query));
    }
}
