<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Metrics;

use MicroModule\Outbox\Domain\OutboxMessageType;

/**
 * Contract for collecting outbox metrics.
 *
 * Implementations of this interface provide metrics collection for the
 * Transactional Outbox Pattern, enabling monitoring of:
 * - Message enqueueing (writes to outbox table)
 * - Message publishing (publishing from outbox to message broker)
 * - Publish failures and retries
 * - Pending message counts (queue depth)
 * - Cleanup operations
 *
 * Metrics are categorized by message type (event/task) for granular
 * visibility into the outbox pipeline performance.
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.5: Monitoring & Cleanup
 */
interface OutboxMetricsInterface
{
    /**
     * Record a message being enqueued to the outbox table.
     *
     * Called when a message is written to the outbox during a transaction.
     * This counter helps track write throughput and patterns.
     *
     * @param OutboxMessageType $type          Message type (event/task)
     * @param string            $aggregateType The aggregate type (e.g., 'news', 'user')
     */
    public function recordMessageEnqueued(OutboxMessageType $type, string $aggregateType): void;

    /**
     * Record a successful message publish.
     *
     * Called when a message is successfully published from the outbox
     * to the message broker. Includes publish duration for latency tracking.
     *
     * @param OutboxMessageType $type            Message type (event/task)
     * @param float             $durationSeconds Time taken to publish in seconds
     */
    public function recordMessagePublished(OutboxMessageType $type, float $durationSeconds): void;

    /**
     * Record a publish failure.
     *
     * Called when publishing a message fails. Includes error categorization
     * for failure analysis and alerting.
     *
     * @param OutboxMessageType $type      Message type (event/task)
     * @param string            $errorType Error category (e.g., 'connection', 'serialization', 'timeout')
     */
    public function recordPublishFailure(OutboxMessageType $type, string $errorType): void;

    /**
     * Record a retry attempt.
     *
     * Called when a message is being retried after a previous failure.
     * Helps track retry patterns and identify problematic messages.
     *
     * @param OutboxMessageType $type       Message type (event/task)
     * @param int               $retryCount Current retry attempt number
     */
    public function recordRetryAttempt(OutboxMessageType $type, int $retryCount): void;

    /**
     * Set the current pending message count (gauge).
     *
     * Called periodically to update the current queue depth.
     * High values indicate publishing backlog.
     *
     * @param OutboxMessageType $type  Message type (event/task)
     * @param int               $count Current pending count
     */
    public function setPendingCount(OutboxMessageType $type, int $count): void;

    /**
     * Record a cleanup operation.
     *
     * Called when old outbox messages are cleaned up.
     * Helps track data retention and storage management.
     *
     * @param int   $deletedCount    Number of messages deleted
     * @param float $durationSeconds Time taken for cleanup in seconds
     */
    public function recordCleanup(int $deletedCount, float $durationSeconds): void;
}
