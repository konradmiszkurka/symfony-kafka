<?php

declare(strict_types=1);

namespace App\Shared\UI\Command;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Publikuje niewysłane eventy z outboxa na Kafkę')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rozmiar partii', '100')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Jeden przebieg i wyjście (do cron/testów)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $once = (bool) $input->getOption('once');

        do {
            $relayed = $this->relay->relayBatch($limit);
            if ($relayed > 0) {
                $output->writeln(sprintf('Zrelayowano %d wiadomości.', $relayed));
            }
            if ($once) {
                break;
            }
            if (0 === $relayed) {
                sleep(1);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
