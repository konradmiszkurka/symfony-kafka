<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerQueryBus implements QueryBusInterface
{
    public function __construct(private MessageBusInterface $queryBus)
    {
    }

    public function ask(object $query): mixed
    {
        try {
            $envelope = $this->queryBus->dispatch($query);
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        }

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);

        return $handled?->getResult();
    }
}
