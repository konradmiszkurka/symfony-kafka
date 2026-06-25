<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Symfony;

use App\Notification\Application\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailer implements Mailer
{
    public function __construct(private MailerInterface $mailer, private string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $this->mailer->send(
            (new Email())->from($this->fromAddress)->to($to)->subject($subject)->text($body)
        );
    }
}
