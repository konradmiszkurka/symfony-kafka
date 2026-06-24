<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Kafka;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class KafkaMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly \RdKafka\Message $message)
    {
    }
}
