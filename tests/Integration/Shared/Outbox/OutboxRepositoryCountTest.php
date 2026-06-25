<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboxRepositoryCountTest extends KernelTestCase
{
    public function testCountUnsentIncludesFreshRow(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(OutboxRepository::class);

        $before = $repo->countUnsent();

        $msg = OutboxMessage::create('TestEvent', '{}');
        $repo->add($msg);
        self::getContainer()->get('doctrine')->getManager()->flush();

        self::assertGreaterThanOrEqual($before + 1, $repo->countUnsent());
    }

    public function testCountStuckWithFutureThresholdIncludesFreshRow(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(OutboxRepository::class);

        $msg = OutboxMessage::create('TestEvent', '{}');
        $repo->add($msg);
        self::getContainer()->get('doctrine')->getManager()->flush();

        // Threshold w przyszłości → świeży wiersz ma createdAt < threshold → jest "zalegający"
        $futureThreshold = new \DateTimeImmutable('+1 minute');
        self::assertGreaterThanOrEqual(1, $repo->countStuck($futureThreshold));
    }

    public function testCountStuckWithPastThresholdExcludesFreshRow(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(OutboxRepository::class);

        // Threshold w przeszłości → świeży wiersz ma createdAt >= threshold → NIE jest zalegający
        $pastThreshold = new \DateTimeImmutable('-1 minute');
        $stuckBefore = $repo->countStuck($pastThreshold);

        $msg = OutboxMessage::create('TestEvent', '{}');
        $repo->add($msg);
        self::getContainer()->get('doctrine')->getManager()->flush();

        self::assertSame($stuckBefore, $repo->countStuck($pastThreshold));
    }
}
