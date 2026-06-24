<?php

declare(strict_types=1);

namespace App\Notification\Application;

use Symfony\Component\Uid\Uuid;

interface RecipientResolver
{
    public function emailFor(Uuid $userId): ?string;
}
