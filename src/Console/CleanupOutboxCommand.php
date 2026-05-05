<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Console;

use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command for cleaning up old outbox messages.
 *
 * Removes published outbox messages older than a specified retention period.
 * This prevents unbounded table growth while preserving recent records
 * for debugging and auditing.
 *
 * Implements:
 * - Configurable retention period (default: 7 days)
 * - Batch deletion for performance
 * - Dry-run mode for preview
 * - Metrics integration for monitoring
 *
 * Usage:
 *   bin/console app:outbox:cleanup                     # Default 7-day retention
 *   bin/console app:outbox:cleanup --retention=30     # 30-day retention
 *   bin/console app:outbox:cleanup --dry-run          # Preview without deleting
 *   bin/console app:outbox:cleanup --batch-size=1000  # Custom batch size
 *
 * Recommended: Run daily via cron during low-traffic periods.
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.5: Monitoring & Cleanup
 */
#[AsCommand(name: 'app:outbox:cleanup', description: 'Clean up old published outbox messages',)]
final class CleanupOutboxCommand extends Command
{
    private const int DEFAULT_RETENTION_DAYS = 7;

    private const int DEFAULT_BATCH_SIZE = 1000;

    private const int DEFAULT_MAX_RETRIES = 5;

    private const int DEFAULT_DEAD_LETTER_RETENTION_DAYS = 90;

    public function __construct(
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly OutboxMetricsInterface $metrics,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention',
                'r',
                InputOption::VALUE_REQUIRED,
                'Retention period in days for published messages',
                self::DEFAULT_RETENTION_DAYS
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of records to delete per batch',
                self::DEFAULT_BATCH_SIZE
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview cleanup without deleting')
            ->addOption(
                'include-failed',
                null,
                InputOption::VALUE_NONE,
                'Include failed messages that exceeded max retries'
            )
            ->addOption(
                'max-retries',
                null,
                InputOption::VALUE_REQUIRED,
                'Retry threshold for --include-failed (must match publisher --max-retries)',
                self::DEFAULT_MAX_RETRIES
            )
            ->addOption(
                'dead-letter-retention',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention period in days for DLQ rows (dead_letter_at older than cutoff is purged); '
                . 'when omitted, DLQ rows are kept indefinitely',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retentionDays = (int) $input->getOption('retention');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = (bool) $input->getOption('dry-run');
        $includeFailed = (bool) $input->getOption('include-failed');
        $maxRetries = (int) $input->getOption('max-retries');
        $dlqRetentionRaw = $input->getOption('dead-letter-retention');
        $dlqRetentionDays = is_numeric($dlqRetentionRaw) ? (int) $dlqRetentionRaw : null;

        // Calculate cutoff date
        $cutoffDate = new \DateTimeImmutable(sprintf('-%d days', $retentionDays));
        $dlqCutoffDate = $dlqRetentionDays !== null
            ? new \DateTimeImmutable(sprintf('-%d days', $dlqRetentionDays))
            : null;

        $io->title('Outbox Cleanup');
        $io->info(array_filter([
            sprintf('Retention period: %d days', $retentionDays),
            sprintf('Cutoff date: %s', $cutoffDate->format('Y-m-d H:i:s')),
            sprintf('Batch size: %d', $batchSize),
            sprintf('Include failed: %s', $includeFailed ? 'yes' : 'no'),
            $includeFailed ? sprintf('Max retries threshold: %d', $maxRetries) : null,
            $dlqCutoffDate !== null
                ? sprintf('DLQ retention: %d days (cutoff %s)', $dlqRetentionDays, $dlqCutoffDate->format('Y-m-d H:i:s'))
                : null,
            $dryRun ? 'DRY RUN MODE - No records will be deleted' : null,
        ]));

        $startTime = microtime(true);
        $totalDeleted = 0;

        try {
            if ($dryRun) {
                // Preview mode - count records that would be deleted
                $countToDelete = $this->outboxRepository->countPublishedBefore($cutoffDate);

                if ($includeFailed) {
                    $failedCount = $this->outboxRepository->countFailedExceedingRetries($maxRetries);
                    $countToDelete += $failedCount;
                    $io->text(sprintf('Failed messages exceeding retries: %d', $failedCount));
                }

                if ($dlqCutoffDate !== null) {
                    $dlqCount = $this->outboxRepository->countDeadLetterBefore($dlqCutoffDate);
                    $countToDelete += $dlqCount;
                    $io->text(sprintf('DLQ rows older than retention: %d', $dlqCount));
                }

                $io->success(sprintf('[DRY-RUN] Would delete %d records', $countToDelete));

                return Command::SUCCESS;
            }

            // Actual cleanup - delete in batches
            $io->section('Deleting old published messages...');

            $deletedThisBatch = 0;
            do {
                $deletedThisBatch = $this->outboxRepository->deletePublishedBefore($cutoffDate, $batchSize);

                $totalDeleted += $deletedThisBatch;

                if ($deletedThisBatch > 0 && $output->isVerbose()) {
                    $io->text(sprintf('  Deleted batch of %d (total: %d)', $deletedThisBatch, $totalDeleted));
                }
            } while ($deletedThisBatch === $batchSize);

            // Clean up failed messages if requested
            if ($includeFailed) {
                $io->section('Deleting failed messages exceeding max retries...');

                $failedDeleted = 0;
                do {
                    $deletedThisBatch = $this->outboxRepository->deleteFailedExceedingRetries(
                        $maxRetries,
                        $batchSize
                    );

                    $failedDeleted += $deletedThisBatch;
                    $totalDeleted += $deletedThisBatch;

                    if ($deletedThisBatch > 0 && $output->isVerbose()) {
                        $io->text(sprintf(
                            '  Deleted batch of %d failed (total: %d)',
                            $deletedThisBatch,
                            $failedDeleted
                        ));
                    }
                } while ($deletedThisBatch === $batchSize);

                $io->text(sprintf('Failed messages deleted: %d', $failedDeleted));
            }

            // Clean up DLQ rows older than retention if requested
            if ($dlqCutoffDate !== null) {
                $io->section('Deleting DLQ rows older than retention...');

                $dlqDeleted = 0;
                do {
                    $deletedThisBatch = $this->outboxRepository->deleteDeadLetterBefore(
                        $dlqCutoffDate,
                        $batchSize
                    );

                    $dlqDeleted += $deletedThisBatch;
                    $totalDeleted += $deletedThisBatch;

                    if ($deletedThisBatch > 0 && $output->isVerbose()) {
                        $io->text(sprintf(
                            '  Deleted batch of %d DLQ (total: %d)',
                            $deletedThisBatch,
                            $dlqDeleted
                        ));
                    }
                } while ($deletedThisBatch === $batchSize);

                $io->text(sprintf('DLQ rows deleted: %d', $dlqDeleted));
            }

            $durationSeconds = microtime(true) - $startTime;

            // Record metrics
            $this->metrics->recordCleanup($totalDeleted, $durationSeconds);

            // Log cleanup completion
            $this->logger->info('Outbox cleanup completed', [
                'deleted_count' => $totalDeleted,
                'retention_days' => $retentionDays,
                'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                'duration_seconds' => round($durationSeconds, 3),
                'included_failed' => $includeFailed,
                'max_retries' => $includeFailed ? $maxRetries : null,
                'dlq_retention_days' => $dlqRetentionDays,
            ]);

            $io->success([
                'Cleanup completed successfully',
                sprintf('Records deleted: %d', $totalDeleted),
                sprintf('Duration: %.2f seconds', $durationSeconds),
                sprintf('Throughput: %.2f records/sec', $totalDeleted / max(0.001, $durationSeconds)),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->logger->error('Outbox cleanup failed', [
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
                'deleted_before_failure' => $totalDeleted,
            ]);

            $io->error([
                'Cleanup failed',
                $throwable->getMessage(),
                sprintf('Records deleted before failure: %d', $totalDeleted),
            ]);

            return Command::FAILURE;
        }
    }
}
