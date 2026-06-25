<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Kafka;

use App\Shared\Infrastructure\Messenger\Kafka\KafkaTransportFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final readonly class SmokePayload
{
    public function __construct(public string $value) {}
}

#[Group('kafka')]
final class KafkaRoundtripTest extends TestCase
{
    public function testSendAndConsumeRoundtrip(): void
    {
        $factory = new KafkaTransportFactory();
        $serializer = new PhpSerializer();
        $topic = 'smoke.test.'.bin2hex(random_bytes(4));

        $transport = $factory->createTransport('kafka://kafka:9092', [
            'topic' => $topic,
            'consumer_group' => 'smoke-'.bin2hex(random_bytes(4)),
            'auto_offset_reset' => 'earliest',
            'consume_timeout_ms' => 10000,
        ], $serializer);

        $sentPayload = new SmokePayload('hello-kafka-'.bin2hex(random_bytes(4)));
        $transport->send(new Envelope($sentPayload));

        $received = null;
        for ($i = 0; $i < 5 && null === $received; ++$i) {
            foreach ($transport->get() as $envelope) {
                $received = $envelope;
                $transport->ack($envelope);
            }
        }

        self::assertNotNull($received, 'No message received from real Kafka.');
        $message = $received->getMessage();
        self::assertInstanceOf(SmokePayload::class, $message, 'Received message should be an instance of SmokePayload.');
        self::assertSame($sentPayload->value, $message->value, 'Payload value does not match what was sent.');
    }
}
