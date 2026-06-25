<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Outbox;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class OutboxStatusCommandTest extends KernelTestCase
{
    public function testStatusOutputContainsExpectedLines(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:outbox:status');
        $tester = new CommandTester($command);

        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Unsent:', $output);
        self::assertStringContainsString('Stuck', $output);
    }

    public function testStatusExitsSuccessfullyWhenNoStuckMessages(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:outbox:status');
        $tester = new CommandTester($command);

        // With an empty outbox (DAMA rollback) there are no stuck messages → SUCCESS
        $exitCode = $tester->execute(['--stuck-after' => '1']);

        self::assertSame(0, $exitCode);
    }
}
