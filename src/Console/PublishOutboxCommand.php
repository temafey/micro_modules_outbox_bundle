<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Console;

use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use MicroModule\Outbox\Infrastructure\Publisher\OutboxPublisherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command for publishing pending outbox messages.
 *
 * Polls the outbox table for pending messages and publishes them
 * to the appropriate message broker (RabbitMQ). Implements:
 * - Batch processing for efficiency
 * - Graceful shutdown via SIGTERM/SIGINT signals
 * - Memory limit monitoring for safe restarts
 * - Exponential backoff retry for failed messages
 * - Dry-run mode for preview without publishing
 *
 * Usage:
 *   bin/console app:outbox:publish --batch-size=100 --poll-interval=5
 *   bin/console app:outbox:publish --run-once    # Single batch (for cron)
 *   bin/console app:outbox:publish --dry-run    # Preview without publishing
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.4: Background Publisher Implementation
 */
#[AsCommand(name: 'app:outbox:publish', description: 'Publish pending outbox messages to RabbitMQ',)]
final class PublishOutboxCommand extends Command
{
    private const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_POLL_INTERVAL = 5;

    private const int DEFAULT_MAX_RETRIES = 5;

    private const int DEFAULT_MEMORY_LIMIT_MB = 256;

    private bool $shouldStop = false;

    public function __construct(
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly OutboxPublisherInterface $publisher,
        private readonly OutboxMetricsInterface $metrics,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of messages to process per batch',
                self::DEFAULT_BATCH_SIZE
            )
            ->addOption(
                'poll-interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Seconds to wait between polls when queue is empty',
                self::DEFAULT_POLL_INTERVAL
            )
            ->addOption(
                'max-retries',
                'r',
                InputOption::VALUE_REQUIRED,
                'Maximum retry attempts before marking as dead letter',
                self::DEFAULT_MAX_RETRIES
            )
            ->addOption(
                'message-type',
                't',
                InputOption::VALUE_REQUIRED,
                'Filter by message type (event|task|all)',
                'all'
            )
            ->addOption(
                'run-once',
                null,
                InputOption::VALUE_NONE,
                'Process one batch and exit (for cron-based execution)'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview messages without publishing')
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit in MB before graceful restart',
                self::DEFAULT_MEMORY_LIMIT_MB
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = (int) $input->getOption('batch-size');
        $pollInterval = (int) $input->getOption('poll-interval');
        $maxRetries = (int) $input->getOption('max-retries');
        $messageTypeFilter = $input->getOption('message-type');
        $runOnce = (bool) $input->getOption('run-once');
        $dryRun = (bool) $input->getOption('dry-run');
        $memoryLimitBytes = (int) $input->getOption('memory-limit') * 1024 * 1024;

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        $io->title('Outbox Publisher');
        $io->info(array_filter([
            sprintf('Batch size: %d', $batchSize),
            sprintf('Poll interval: %d seconds', $pollInterval),
            sprintf('Max retries: %d', $maxRetries),
            sprintf('Message type filter: %s', $messageTypeFilter),
            sprintf('Memory limit: %d MB', $memoryLimitBytes / 1024 / 1024),
            $dryRun ? 'DRY RUN MODE - No messages will be published' : null,
        ]));

        $totalProcessed = 0;
        $totalFailed = 0;
        $startTime = time();

        do {
            // Check for graceful shutdown signal
            if ($this->shouldStop) {
                $io->warning('Received shutdown signal, stopping gracefully...');
                break;
            }

            // Check memory limit
            if (memory_get_usage(true) > $memoryLimitBytes) {
                $io->warning('Memory limit reached, stopping for restart...');
                break;
            }

            try {
                // Update pending count metrics
                $this->updatePendingCountMetrics();

                // Fetch pending messages
                $messages = $this->fetchPendingMessages($batchSize, $messageTypeFilter, $maxRetries);

                if ($messages === []) {
                    if ($runOnce) {
                        $io->success('No pending messages. Exiting.');
                        break;
                    }

                    $io->text(sprintf(
                        '[%s] No pending messages, sleeping %ds...',
                        date('Y-m-d H:i:s'),
                        $pollInterval
                    ));
                    sleep($pollInterval);
                    continue;
                }

                $io->section(sprintf('Processing batch of %d messages', count($messages)));

                foreach ($messages as $message) {
                    try {
                        if ($dryRun) {
                            $io->text(sprintf(
                                '  [DRY-RUN] Would publish: %s (type=%s, id=%s)',
                                $message->getEventType(),
                                $message->getMessageType()
                                    ->value,
                                $message->getId()
                            ));
                            continue;
                        }

                        // Publish the message
                        $publishStartTime = microtime(true);
                        $this->publisher->publish($message);
                        $publishDuration = microtime(true) - $publishStartTime;

                        // Mark as published
                        $this->outboxRepository->markAsPublished([$message->getId()], new \DateTimeImmutable());

                        // Record successful publish metrics
                        $this->metrics->recordMessagePublished($message->getMessageType(), $publishDuration);

                        ++$totalProcessed;

                        if ($output->isVerbose()) {
                            $io->text(sprintf(
                                '  ✓ Published: %s (id=%s, latency=%dms)',
                                $message->getEventType(),
                                $message->getId(),
                                $this->calculateLatencyMs($message->getCreatedAt())
                            ));
                        }
                    } catch (\Throwable $e) {
                        ++$totalFailed;

                        // Record failure metrics
                        $errorType = $this->classifyError($e);
                        $this->metrics->recordPublishFailure($message->getMessageType(), $errorType);

                        $this->logger->error('Failed to publish outbox message', [
                            'message_id' => $message->getId(),
                            'message_type' => $message->getMessageType()
                                ->value,
                            'event_type' => $message->getEventType(),
                            'error' => $e->getMessage(),
                            'error_type' => $errorType,
                            'retry_count' => $message->getRetryCount(),
                        ]);

                        // Always persist the failure: increment retry_count, schedule next_retry_at
                        // and store last_error. Required even when the row will cross the retry
                        // budget on this attempt, so retry_count and last_error reflect the most
                        // recent failure for postmortem.
                        $this->outboxRepository->markAsFailed(
                            $message->getId(),
                            $e->getMessage(),
                            $this->calculateNextRetryAt($message->getRetryCount())
                        );

                        $newRetryCount = $message->getRetryCount() + 1;

                        // Record retry attempt metrics
                        $this->metrics->recordRetryAttempt($message->getMessageType(), $newRetryCount);

                        // Crossing the retry budget — stamp dead_letter_at so the row is excluded
                        // from polling via SQL filter (defence in depth: fetchPendingMessages also
                        // filters by retry_count). Operator can replay via app:outbox:dlq.
                        if ($newRetryCount >= $maxRetries) {
                            $this->outboxRepository->markAsDeadLetter(
                                $message->getId(),
                                new \DateTimeImmutable()
                            );

                            $this->logger->critical('Outbox message exceeded max retries, marked as dead-letter', [
                                'message_id' => $message->getId(),
                                'message_type' => $message->getMessageType()
                                    ->value,
                                'event_type' => $message->getEventType(),
                                'aggregate_type' => $message->getAggregateType(),
                                'aggregate_id' => $message->getAggregateId(),
                                'retry_count' => $newRetryCount,
                                'max_retries' => $maxRetries,
                                'last_error' => $e->getMessage(),
                                'error_type' => $errorType,
                            ]);
                        }

                        $io->error(sprintf(
                            '  ✗ Failed: %s - %s (retry %d/%d)',
                            $message->getEventType(),
                            $e->getMessage(),
                            $newRetryCount,
                            $maxRetries
                        ));
                    }
                }

                // Process signals between batches
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            } catch (\Throwable $e) {
                $this->logger->critical('Outbox publisher error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $io->error(sprintf('Publisher error: %s', $e->getMessage()));

                // Wait before retrying to avoid tight loop on persistent errors
                sleep($pollInterval);
            }
        } while (! $runOnce);

        // Summary
        $runtime = time() - $startTime;
        $io->newLine();
        $io->success([
            'Outbox Publisher Summary',
            sprintf('Runtime: %d seconds', $runtime),
            sprintf('Messages processed: %d', $totalProcessed),
            sprintf('Messages failed: %d', $totalFailed),
            sprintf('Throughput: %.2f msg/sec', $totalProcessed / max(1, $runtime)),
        ]);

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Fetch pending messages based on filter.
     *
     * @return OutboxEntryInterface[]
     */
    private function fetchPendingMessages(int $limit, string $messageTypeFilter, int $maxRetries): array
    {
        // Filter out messages that exceeded max retries in application code
        // The repository returns all unpublished messages; we filter here
        if ($messageTypeFilter === 'all') {
            $messages = $this->outboxRepository->findUnpublished($limit);
        } else {
            $messageType = $this->parseMessageType($messageTypeFilter);
            $messages = $this->outboxRepository->findUnpublishedByType($messageType, $limit);
        }

        // Strict less-than: a row whose retry_count has already reached the budget is treated
        // as a dead-letter and skipped. The cleanup command (--include-failed) is responsible
        // for purging or operator-driven replay.
        return array_filter(
            $messages,
            static fn (OutboxEntryInterface $m): bool => $m->getRetryCount() < $maxRetries
        );
    }

    /**
     * Parse message type string to enum.
     */
    private function parseMessageType(string $type): OutboxMessageType
    {
        return match ($type) {
            'event' => OutboxMessageType::EVENT,
            'task' => OutboxMessageType::TASK,
            default => throw new \InvalidArgumentException(sprintf(
                'Invalid message type: %s. Expected: event, task, all',
                $type
            )),
        };
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGQUIT, $handler);
    }

    /**
     * Calculate latency in milliseconds from creation time.
     */
    private function calculateLatencyMs(\DateTimeImmutable $createdAt): int
    {
        $now = new \DateTimeImmutable();
        $diffMs = ($now->getTimestamp() - $createdAt->getTimestamp()) * 1000;
        $diffMs += (int) (($now->format('u') - $createdAt->format('u')) / 1000);

        return max(0, $diffMs);
    }

    /**
     * Calculate next retry time with exponential backoff.
     *
     * Backoff formula: 2^retryCount seconds, capped at 5 minutes (300s)
     */
    private function calculateNextRetryAt(int $retryCount): \DateTimeImmutable
    {
        $delaySeconds = min(300, 2 ** $retryCount);

        return new \DateTimeImmutable(sprintf('+%d seconds', $delaySeconds));
    }

    /**
     * Update pending count metrics for all message types.
     *
     * Uses the existing getMetrics() method which returns total_events and total_tasks.
     */
    private function updatePendingCountMetrics(): void
    {
        try {
            // Use existing getMetrics() which returns pending counts by type
            $metrics = $this->outboxRepository->getMetrics();

            // Update pending count for events
            $this->metrics->setPendingCount(OutboxMessageType::EVENT, $metrics['total_events']);

            // Update pending count for tasks
            $this->metrics->setPendingCount(OutboxMessageType::TASK, $metrics['total_tasks']);
        } catch (\Throwable $throwable) {
            $this->logger->warning('Failed to update pending count metrics', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Classify error type for metrics cardinality control.
     *
     * Reduces high-cardinality error messages to known categories.
     */
    private function classifyError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        $className = $e::class;

        return match (true) {
            str_contains($message, 'connection') || str_contains($message, 'socket') => 'connection_error',
            str_contains($message, 'timeout') => 'timeout_error',
            str_contains($message, 'queue') || str_contains($message, 'amqp') => 'queue_error',
            str_contains($message, 'serialize') || str_contains($message, 'json') => 'serialization_error',
            str_contains($className, 'AMQPException') || str_contains(
                $className,
                'AMQPChannelException'
            ) => 'amqp_error',
            str_contains($className, 'PDOException') || str_contains($className, 'DBAL') => 'database_error',
            default => 'unknown_error',
        };
    }
}
