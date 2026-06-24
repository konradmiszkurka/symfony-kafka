<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBusInterface
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function dispatch(object $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            // Odpakuj oryginalny wyjątek domenowy z opakowania Messengera.
            throw $e->getPrevious() ?? $e;
        }
    }
}
