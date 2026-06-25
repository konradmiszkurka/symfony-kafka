<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransport implements TransportInterface
{
    private ?\RdKafka\Producer $producer = null;
    private ?\RdKafka\KafkaConsumer $consumer = null;

    /**
     * @param array{topic?: string, consumer_group?: string, auto_offset_reset?: string, consume_timeout_ms?: int, flush_timeout_ms?: int} $options
     */
    public function __construct(
        private readonly string $brokers,
        private readonly array $options,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        $encoded = $this->serializer->encode($envelope);

        $headers = [];
        foreach (($encoded['headers'] ?? []) as $name => $value) {
            $headers[$name] = (string) $value;
        }

        $producer = $this->producer();
        $topic = $producer->newTopic($this->topic());
        // Partition key = null: no partition affinity (Kafka distributes round-robin).
        // For per-aggregate ordering, a key (e.g. userId) can be passed in the future
        // via a dedicated stamp and supplied as the 4th argument to producev().
        $topic->producev(RD_KAFKA_PARTITION_UA, 0, $encoded['body'], null, $headers);
        $producer->poll(0);

        $result = $producer->flush($this->options['flush_timeout_ms'] ?? 10000);
        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new TransportException('Failed to flush message to Kafka.');
        }

        return $envelope;
    }

    public function get(): iterable
    {
        $message = $this->consumer()->consume($this->options['consume_timeout_ms'] ?? 1000);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                $envelope = $this->serializer->decode([
                    'body' => $message->payload,
                    'headers' => $message->headers ?? [],
                ]);

                return [$envelope->with(new KafkaMessageStamp($message))];
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                return [];
            default:
                throw new TransportException($message->errstr());
        }
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $envelope->last(KafkaMessageStamp::class);
        if (!$stamp instanceof KafkaMessageStamp) {
            throw new TransportException('Missing KafkaMessageStamp on ack() — cannot commit offset.');
        }

        $this->consumer()->commit($stamp->message);
    }

    public function reject(Envelope $envelope): void
    {
        // At-least-once: no commit => message will be redelivered.
    }

    private function topic(): string
    {
        return $this->options['topic'] ?? throw new TransportException('Missing "topic" option for Kafka transport.');
    }

    private function producer(): \RdKafka\Producer
    {
        if (null === $this->producer) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $this->producer = new \RdKafka\Producer($conf);
        }

        return $this->producer;
    }

    private function consumer(): \RdKafka\KafkaConsumer
    {
        if (null === $this->consumer) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', $this->brokers);
            $conf->set('group.id', $this->options['consumer_group']
                ?? throw new TransportException('Missing "consumer_group" option for Kafka transport.'));
            $conf->set('auto.offset.reset', $this->options['auto_offset_reset'] ?? 'earliest');
            $conf->set('enable.auto.commit', 'false');
            $consumer = new \RdKafka\KafkaConsumer($conf);
            $consumer->subscribe([$this->topic()]);
            $this->consumer = $consumer;
        }

        return $this->consumer;
    }
}
