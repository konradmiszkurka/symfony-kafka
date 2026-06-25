<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class OutboxRelay
{
    public function __construct(
        private OutboxRepository $outbox,
        private MessageBusInterface $eventBus,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
    ) {
    }

    public function relayBatch(int $limit = 100): int
    {
        $relayed = 0;

        foreach ($this->outbox->unsent($limit) as $message) {
            try {
                $event = $this->serializer->deserialize($message->getPayload(), $message->getMessageType(), 'json');
                $this->eventBus->dispatch($event);
                $message->markSent(new \DateTimeImmutable());
                $this->outbox->save($message);
                ++$relayed;
            } catch (\Throwable $e) {
                $this->logger->error('Relay outboxa nie powiódł się dla wiadomości {id}', [
                    'id' => (string) $message->getId(),
                    'exception' => $e,
                ]);
            }
        }

        return $relayed;
    }
}
