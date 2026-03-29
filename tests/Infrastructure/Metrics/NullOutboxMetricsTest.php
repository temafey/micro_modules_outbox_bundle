<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure\Metrics;

use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Infrastructure\Metrics\NullOutboxMetrics;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullOutboxMetrics::class)]
final class NullOutboxMetricsTest extends TestCase
{
    private NullOutboxMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new NullOutboxMetrics();
    }

    #[Test]
    public function implementsOutboxMetricsInterface(): void
    {
        self::assertInstanceOf(OutboxMetricsInterface::class, $this->metrics);
    }

    #[Test]
    public function recordMessageEnqueuedDoesNotThrow(): void
    {
        $this->metrics->recordMessageEnqueued(OutboxMessageType::EVENT, 'News');

        // No-op - just verify it doesn't throw
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function recordMessagePublishedDoesNotThrow(): void
    {
        $this->metrics->recordMessagePublished(OutboxMessageType::EVENT, 0.5);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function recordPublishFailureDoesNotThrow(): void
    {
        $this->metrics->recordPublishFailure(OutboxMessageType::TASK, 'connection_error');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function recordRetryAttemptDoesNotThrow(): void
    {
        $this->metrics->recordRetryAttempt(OutboxMessageType::EVENT, 3);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function setPendingCountDoesNotThrow(): void
    {
        $this->metrics->setPendingCount(OutboxMessageType::TASK, 42);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function recordCleanupDoesNotThrow(): void
    {
        $this->metrics->recordCleanup(100, 2.5);

        $this->addToAssertionCount(1);
    }
}
