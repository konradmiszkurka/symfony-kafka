<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface EventBusInterface
{
    public function publish(object $event): void;
}
