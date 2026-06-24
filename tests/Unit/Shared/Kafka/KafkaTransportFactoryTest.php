<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Kafka;

use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransport;
use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class KafkaTransportFactoryTest extends TestCase
{
    public function testSupportsKafkaDsnOnly(): void
    {
        $factory = new KafkaTransportFactory();

        self::assertTrue($factory->supports('kafka://kafka:9092', []));
        self::assertFalse($factory->supports('in-memory://', []));
        self::assertFalse($factory->supports('doctrine://default', []));
    }

    public function testCreatesKafkaTransport(): void
    {
        $factory = new KafkaTransportFactory();
        $serializer = $this->createStub(SerializerInterface::class);

        $transport = $factory->createTransport('kafka://kafka:9092', ['topic' => 'enrollment.events'], $serializer);

        self::assertInstanceOf(KafkaTransport::class, $transport);
    }
}
