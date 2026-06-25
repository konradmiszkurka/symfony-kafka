<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransportFactory implements TransportFactoryInterface
{
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'kafka://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $brokers = substr($dsn, \strlen('kafka://'));
        // Strip any query string from the DSN (options come via $options).
        $brokers = explode('?', $brokers)[0];

        unset($options['transport_name']);

        return new KafkaTransport($brokers, $options, $serializer);
    }
}
