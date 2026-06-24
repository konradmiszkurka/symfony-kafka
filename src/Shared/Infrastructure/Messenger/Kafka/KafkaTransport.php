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
        // Klucz partycji = null: brak powinowactwa partycji (Kafka rozkłada round-robin).
        // Dla porządkowania per-agregat można w przyszłości przekazać klucz (np. userId)
        // przez dedykowany stamp i podać go jako 4. argument producev().
        $topic->producev(RD_KAFKA_PARTITION_UA, 0, $encoded['body'], null, $headers);
        $producer->poll(0);

        $result = $producer->flush($this->options['flush_timeout_ms'] ?? 10000);
        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new TransportException('Nie udało się wysłać wiadomości do Kafki (flush).');
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
            throw new TransportException('Brak KafkaMessageStamp przy ack() — nie można scommitować offsetu.');
        }

        $this->consumer()->commit($stamp->message);
    }

    public function reject(Envelope $envelope): void
    {
        // At-least-once: brak commitu => wiadomość zostanie dostarczona ponownie.
    }

    private function topic(): string
    {
        return $this->options['topic'] ?? throw new TransportException('Brak opcji "topic" dla transportu Kafka.');
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
                ?? throw new TransportException('Brak opcji "consumer_group" dla transportu Kafka.'));
            $conf->set('auto.offset.reset', $this->options['auto_offset_reset'] ?? 'earliest');
            $conf->set('enable.auto.commit', 'false');
            $consumer = new \RdKafka\KafkaConsumer($conf);
            $consumer->subscribe([$this->topic()]);
            $this->consumer = $consumer;
        }

        return $this->consumer;
    }
}
