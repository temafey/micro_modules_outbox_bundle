<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Metrics;

use MicroModule\Outbox\Domain\OutboxMessageType;

/**
 * No-op implementation of outbox metrics.
 *
 * Used when metrics collection is disabled or for testing.
 * All methods are empty implementations that do nothing.
 *
 * @see OutboxMetricsInterface
 * @see TASK-14.5: Monitoring & Cleanup
 */
final class NullOutboxMetrics implements OutboxMetricsInterface
{
    public function recordMessageEnqueued(OutboxMessageType $type, string $aggregateType): void
    {
        // No-op
    }

    public function recordMessagePublished(OutboxMessageType $type, float $durationSeconds): void
    {
        // No-op
    }

    public function recordPublishFailure(OutboxMessageType $type, string $errorType): void
    {
        // No-op
    }

    public function recordRetryAttempt(OutboxMessageType $type, int $retryCount): void
    {
        // No-op
    }

    public function setPendingCount(OutboxMessageType $type, int $count): void
    {
        // No-op
    }

    public function recordCleanup(int $deletedCount, float $durationSeconds): void
    {
        // No-op
    }
}
