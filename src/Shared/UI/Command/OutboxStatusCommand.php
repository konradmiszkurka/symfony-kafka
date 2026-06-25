<?php

declare(strict_types=1);

namespace App\Shared\UI\Command;

use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:outbox:status', 'Outbox status: unsent / stuck')]
final class OutboxStatusCommand extends Command
{
    public function __construct(private readonly OutboxRepository $outboxRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'stuck-after',
            null,
            InputOption::VALUE_REQUIRED,
            'Stuck threshold in minutes',
            '5'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $minutes = (int) $input->getOption('stuck-after');
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $minutes));

        $unsent = $this->outboxRepository->countUnsent();
        $stuck = $this->outboxRepository->countStuck($threshold);

        $output->writeln(sprintf('Unsent: %d', $unsent));
        $output->writeln(sprintf('Stuck (>%d min): %d', $minutes, $stuck));

        return $stuck > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
