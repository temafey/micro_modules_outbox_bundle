<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Metrics;

use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Infrastructure\Observability\MeterFactoryInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * OpenTelemetry-based implementation of outbox metrics.
 *
 * Provides comprehensive metrics using OpenTelemetry SDK:
 * - Counters for message operations (enqueue, publish, failure, retry)
 * - Histograms for latency distribution (publish duration, cleanup duration)
 * - Gauges for current state (pending count)
 *
 * Metrics naming follows OpenTelemetry semantic conventions:
 * - `outbox.messages.enqueued` - Counter of messages written to outbox
 * - `outbox.messages.published` - Counter of successfully published messages
 * - `outbox.messages.failed` - Counter of failed publish attempts
 * - `outbox.messages.retried` - Counter of retry attempts
 * - `outbox.messages.pending` - Gauge of pending messages
 * - `outbox.publish.duration` - Histogram of publish latency
 * - `outbox.cleanup.duration` - Histogram of cleanup operation latency
 * - `outbox.cleanup.deleted` - Counter of deleted messages
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.5: Monitoring & Cleanup
 */
final class OpenTelemetryOutboxMetrics implements OutboxMetricsInterface
{
    private CounterInterface $enqueuedCounter;

    private CounterInterface $publishedCounter;

    private CounterInterface $failedCounter;

    private CounterInterface $retriedCounter;

    private CounterInterface $cleanupDeletedCounter;

    private HistogramInterface $publishDurationHistogram;

    private HistogramInterface $cleanupDurationHistogram;

    /**
     * Pending counts stored per message type for observable gauge callback.
     *
     * @var array<string, int>
     */
    private array $pendingCounts = [];

    public function __construct(
        private readonly MeterFactoryInterface $meterFactory,
    ) {
        $this->initializeMetrics();
    }

    public function recordMessageEnqueued(OutboxMessageType $type, string $aggregateType): void
    {
        $this->enqueuedCounter->add(1, [
            'message_type' => $type->value,
            'aggregate_type' => $aggregateType,
        ]);
    }

    public function recordMessagePublished(OutboxMessageType $type, float $durationSeconds): void
    {
        $this->publishedCounter->add(1, [
            'message_type' => $type->value,
        ]);

        $this->publishDurationHistogram->record($durationSeconds, [
            'message_type' => $type->value,
        ]);
    }

    public function recordPublishFailure(OutboxMessageType $type, string $errorType): void
    {
        $this->failedCounter->add(1, [
            'message_type' => $type->value,
            'error_type' => $errorType,
        ]);
    }

    public function recordRetryAttempt(OutboxMessageType $type, int $retryCount): void
    {
        $this->retriedCounter->add(1, [
            'message_type' => $type->value,
            'retry_count' => (string) min($retryCount, 5), // Cap at 5 for cardinality
        ]);
    }

    public function setPendingCount(OutboxMessageType $type, int $count): void
    {
        $this->pendingCounts[$type->value] = $count;
    }

    public function recordCleanup(int $deletedCount, float $durationSeconds): void
    {
        $this->cleanupDeletedCounter->add($deletedCount);

        $this->cleanupDurationHistogram->record($durationSeconds, [
            'deleted_count_bucket' => $this->getBucket($deletedCount),
        ]);
    }

    /**
     * Initialize all metrics instruments.
     */
    private function initializeMetrics(): void
    {
        $meter = $this->meterFactory->getMeter();

        // Counters
        $this->enqueuedCounter = $meter->createCounter(
            'outbox.messages.enqueued',
            '{message}',
            'Number of messages enqueued to the outbox table'
        );

        $this->publishedCounter = $meter->createCounter(
            'outbox.messages.published',
            '{message}',
            'Number of messages successfully published from outbox'
        );

        $this->failedCounter = $meter->createCounter(
            'outbox.messages.failed',
            '{message}',
            'Number of failed publish attempts'
        );

        $this->retriedCounter = $meter->createCounter(
            'outbox.messages.retried',
            '{message}',
            'Number of retry attempts'
        );

        $this->cleanupDeletedCounter = $meter->createCounter(
            'outbox.cleanup.deleted',
            '{message}',
            'Number of messages deleted during cleanup'
        );

        // Histograms with seconds unit (OTel semantic convention)
        $this->publishDurationHistogram = $meter->createHistogram(
            'outbox.publish.duration',
            's',
            'Time taken to publish a message from outbox'
        );

        $this->cleanupDurationHistogram = $meter->createHistogram(
            'outbox.cleanup.duration',
            's',
            'Time taken for cleanup operations'
        );

        // Observable gauge for pending counts
        $meter->createObservableGauge(
            'outbox.messages.pending',
            '{message}',
            'Current number of pending messages in the outbox',
            function (ObserverInterface $observer): void {
                foreach ($this->pendingCounts as $messageType => $count) {
                    $observer->observe($count, [
                        'message_type' => $messageType,
                    ]);
                }
            }
        );
    }

    /**
     * Get bucket label for count values (reduces cardinality).
     */
    private function getBucket(int $count): string
    {
        return match (true) {
            $count === 0 => '0',
            $count <= 10 => '1-10',
            $count <= 100 => '11-100',
            $count <= 1000 => '101-1000',
            default => '1000+',
        };
    }
}
