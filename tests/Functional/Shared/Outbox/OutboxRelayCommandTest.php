<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\UI\Command\OutboxRelayCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class OutboxRelayCommandTest extends KernelTestCase
{
    public function testRunWithOnceExitsSuccessfully(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:outbox:relay');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--once' => true]);

        self::assertSame(0, $exitCode);
    }

    public function testCommandImplementsSignalableCommandInterfaceAndSubscribesSignals(): void
    {
        self::bootKernel();

        /** @var OutboxRelayCommand $command */
        $command = self::getContainer()->get(OutboxRelayCommand::class);

        self::assertInstanceOf(SignalableCommandInterface::class, $command);
        self::assertContains(\SIGTERM, $command->getSubscribedSignals());
        self::assertContains(\SIGINT, $command->getSubscribedSignals());
    }
}
