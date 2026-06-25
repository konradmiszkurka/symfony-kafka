<?php

declare(strict_types=1);

namespace App\Shared\UI\Command;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Publikuje niewysłane eventy z outboxa na Kafkę')]
final class OutboxRelayCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rozmiar partii', '100')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Jeden przebieg i wyjście (do cron/testów)')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Czas oczekiwania (w sekundach) gdy batch jest pusty', '1');
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $once = (bool) $input->getOption('once');
        $sleep = (int) $input->getOption('sleep');

        while (!$this->shouldStop) {
            $relayed = $this->relay->relayBatch($limit);
            if ($relayed > 0) {
                $output->writeln(sprintf('Zrelayowano %d wiadomości.', $relayed));
            }
            if ($once || $this->shouldStop) {
                break;
            }
            if (0 === $relayed) {
                sleep($sleep);
            }
        }

        if ($this->shouldStop) {
            $output->writeln('Zatrzymano relay.');
        }

        return Command::SUCCESS;
    }
}
