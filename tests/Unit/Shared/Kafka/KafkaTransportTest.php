<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Kafka;

use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class KafkaTransportTest extends TestCase
{
    public function testAckWithoutStampThrowsTransportException(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $transport = new KafkaTransport(
            'kafka:9092',
            ['topic' => 'enrollment.events', 'consumer_group' => 'test-group'],
            $serializer,
        );

        $this->expectException(TransportException::class);

        $transport->ack(new Envelope(new \stdClass()));
    }

    public function testGetWithoutConsumerGroupThrowsTransportException(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $transport = new KafkaTransport(
            'kafka:9092',
            ['topic' => 'enrollment.events'],
            $serializer,
        );

        $this->expectException(TransportException::class);

        $transport->get();
    }

    public function testRejectIsNoOp(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $transport = new KafkaTransport(
            'kafka:9092',
            ['topic' => 'enrollment.events', 'consumer_group' => 'test-group'],
            $serializer,
        );

        $transport->reject(new Envelope(new \stdClass()));

        self::assertTrue(true);
    }
}
