<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\EventBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class OutboxEventBus implements EventBusInterface
{
    public function __construct(
        private OutboxRepository $outbox,
        private SerializerInterface $serializer,
    ) {
    }

    public function publish(object $event): void
    {
        $this->outbox->add(
            OutboxMessage::create($event::class, $this->serializer->serialize($event, 'json'))
        );
    }
}
