<?php

declare(strict_types=1);

namespace App\Notification\Application;

interface Mailer
{
    public function send(string $to, string $subject, string $body): void;
}
