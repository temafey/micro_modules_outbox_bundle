<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Console;

use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command for inspecting and replaying dead-letter outbox entries.
 *
 * DLQ rows are entries where retry_count crossed --max-retries during publish;
 * they have dead_letter_at set and are excluded from polling. This command lets
 * operators inspect them and replay individual rows once the underlying issue
 * is fixed (e.g. broken consumer, bad payload, RabbitMQ outage).
 *
 * Usage:
 *   bin/console app:outbox:dlq                       # Default: list 50 oldest
 *   bin/console app:outbox:dlq --limit=100           # List 100 oldest
 *   bin/console app:outbox:dlq --replay=<uuid>       # Replay a single row
 *   bin/console app:outbox:dlq --count               # Just print the count
 *
 * @see ADR-006: Transactional Outbox Pattern
 */
#[AsCommand(name: 'app:outbox:dlq', description: 'List or replay dead-letter outbox entries',)]
final class DlqOutboxCommand extends Command
{
    private const int DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum DLQ rows to list',
                self::DEFAULT_LIMIT
            )
            ->addOption(
                'replay',
                null,
                InputOption::VALUE_REQUIRED,
                'Replay a single DLQ row by id (UUID)'
            )
            ->addOption('count', null, InputOption::VALUE_NONE, 'Print only the DLQ row count');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $replayId = $input->getOption('replay');

        if (is_string($replayId) && $replayId !== '') {
            return $this->replay($io, $replayId);
        }

        if ((bool) $input->getOption('count')) {
            $io->writeln((string) $this->outboxRepository->countDeadLetter());

            return Command::SUCCESS;
        }

        return $this->list($io, (int) $input->getOption('limit'));
    }

    private function list(SymfonyStyle $io, int $limit): int
    {
        $io->title('Outbox Dead-Letter Queue');

        $totalDlq = $this->outboxRepository->countDeadLetter();

        if ($totalDlq === 0) {
            $io->success('DLQ is empty.');

            return Command::SUCCESS;
        }

        $entries = $this->outboxRepository->findDeadLetter($limit);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry->getId(),
                $entry->getMessageType()->value,
                $entry->getAggregateType(),
                $entry->getAggregateId(),
                $entry->getEventType(),
                $entry->getRetryCount(),
                $entry->getDeadLetterAt()?->format('Y-m-d H:i:s'),
                $this->truncate($entry->getLastError() ?? ''),
            ];
        }

        $io->table(
            ['ID', 'Type', 'Aggregate', 'Aggregate ID', 'Event', 'Retries', 'DLQ At', 'Last Error'],
            $rows,
        );

        $io->info(sprintf('Showing %d of %d DLQ entries.', count($entries), $totalDlq));
        $io->note('To replay: bin/console app:outbox:dlq --replay=<id>');

        return Command::SUCCESS;
    }

    private function replay(SymfonyStyle $io, string $id): int
    {
        $io->title(sprintf('Replay DLQ entry %s', $id));

        try {
            $replayed = $this->outboxRepository->replayDeadLetter($id);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to replay DLQ entry', [
                'id' => $id,
                'error' => $throwable->getMessage(),
            ]);
            $io->error(['Replay failed', $throwable->getMessage()]);

            return Command::FAILURE;
        }

        if (! $replayed) {
            $io->warning(sprintf('No DLQ row matched id %s (already replayed, published, or unknown).', $id));

            return Command::FAILURE;
        }

        $this->logger->info('DLQ entry replayed', ['id' => $id]);
        $io->success([
            'Replay scheduled.',
            'The next publisher poll will pick up the row (retry_count reset to 0).',
        ]);

        return Command::SUCCESS;
    }

    private function truncate(string $s, int $max = 60): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max - 3) . '...';
    }
}
