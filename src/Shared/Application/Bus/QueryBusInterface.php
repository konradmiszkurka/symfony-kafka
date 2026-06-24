<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

interface QueryBusInterface
{
    public function ask(object $query): mixed;
}
