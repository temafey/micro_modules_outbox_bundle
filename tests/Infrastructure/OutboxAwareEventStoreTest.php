<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure;

use Broadway\Domain\DateTime;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\Domain\Metadata;
use Broadway\EventStore\EventStore;
use MicroModule\Outbox\Domain\DomainEventSerializerInterface;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use MicroModule\Outbox\Infrastructure\OutboxAwareEventStore;
use MicroModule\Outbox\Infrastructure\OutboxFeatureFlag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxAwareEventStore::class)]
final class OutboxAwareEventStoreTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers — anonymous-class fakes matching the sibling tests' style
    // ---------------------------------------------------------------------------

    /**
     * @return array{store: EventStore, appended: \ArrayObject<int, array{id: mixed, stream: DomainEventStream}>}
     */
    private function makeRecordingInnerStore(): array
    {
        $appended = new \ArrayObject();

        $store = new class ($appended) implements EventStore {
            /** @param \ArrayObject<int, array{id: mixed, stream: DomainEventStream}> $appended */
            public function __construct(private \ArrayObject $appended)
            {
            }

            public function append(mixed $id, DomainEventStream $eventStream): void
            {
                $this->appended->append(['id' => $id, 'stream' => $eventStream]);
            }

            public function load(mixed $id): DomainEventStream
            {
                return new DomainEventStream([]);
            }

            public function loadFromPlayhead(mixed $id, int $playhead): DomainEventStream
            {
                return new DomainEventStream([]);
            }
        };

        return ['store' => $store, 'appended' => $appended];
    }

    /**
     * @return array{repo: OutboxRepositoryInterface, log: \ArrayObject<int, OutboxEntryInterface>}
     */
    private function makeRecordingRepository(): array
    {
        $log = new \ArrayObject();

        $repo = new class ($log) implements OutboxRepositoryInterface {
            /** @param \ArrayObject<int, OutboxEntryInterface> $log */
            public function __construct(private \ArrayObject $log)
            {
            }

            public function save(OutboxEntryInterface $entry): void
            {
                $this->log->append($entry);
            }

            public function saveAll(array $entries): void
            {
                foreach ($entries as $entry) {
                    $this->log->append($entry);
                }
            }

            public function findUnpublished(int $limit): array
            {
                return [];
            }

            public function findUnpublishedByType(OutboxMessageType $type, int $limit): array
            {
                return [];
            }

            public function markAsPublished(array $ids, \DateTimeImmutable $publishedAt): int
            {
                return 0;
            }

            public function markAsFailed(string $id, string $error, \DateTimeImmutable $nextRetryAt): void
            {
            }

            public function deletePublishedOlderThan(\DateTimeImmutable $olderThan): int
            {
                return 0;
            }

            public function countPublishedBefore(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function deletePublishedBefore(\DateTimeImmutable $before, int $limit): int
            {
                return 0;
            }

            public function countFailedExceedingRetries(int $maxRetries): int
            {
                return 0;
            }

            public function deleteFailedExceedingRetries(int $maxRetries, int $limit): int
            {
                return 0;
            }

            public function findById(string $id): ?OutboxEntryInterface
            {
                return null;
            }

            public function getMetrics(): array
            {
                return [
                    'total_pending' => 0,
                    'total_events' => 0,
                    'total_tasks' => 0,
                    'failed_count' => 0,
                    'oldest_pending_seconds' => null,
                ];
            }

            public function countByStatus(): array
            {
                return ['pending' => 0, 'published' => 0, 'failed' => 0];
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollback(): void
            {
            }

            public function isTransactionActive(): bool
            {
                return false;
            }
        };

        return ['repo' => $repo, 'log' => $log];
    }

    private function makeStubSerializer(): DomainEventSerializerInterface
    {
        return new class implements DomainEventSerializerInterface {
            public function serialize(DomainMessage $message): array
            {
                return ['payload' => 'stub'];
            }

            public function extractEventType(DomainMessage $message): string
            {
                return 'StubEvent';
            }

            public function determineTopicName(DomainMessage $message): string
            {
                return 'stub.topic';
            }

            public function determineRoutingKey(DomainMessage $message): string
            {
                return 'stub.key';
            }
        };
    }

    private function makeNullMetrics(): OutboxMetricsInterface
    {
        return new class implements OutboxMetricsInterface {
            public function recordMessageEnqueued(OutboxMessageType $type, string $aggregateType): void
            {
            }

            public function recordMessagePublished(OutboxMessageType $type, float $durationSeconds): void
            {
            }

            public function recordPublishFailure(OutboxMessageType $type, string $errorType): void
            {
            }

            public function recordRetryAttempt(OutboxMessageType $type, int $retryCount): void
            {
            }

            public function setPendingCount(OutboxMessageType $type, int $count): void
            {
            }

            public function recordCleanup(int $deletedCount, float $durationSeconds): void
            {
            }
        };
    }

    private function makeSingleEventStream(): DomainEventStream
    {
        return new DomainEventStream([
            new DomainMessage(
                '00000000-0000-0000-0000-000000000001',
                1,
                new Metadata(['aggregate_type' => 'News']),
                new \stdClass(),
                DateTime::now(),
            ),
        ]);
    }

    // ---------------------------------------------------------------------------
    // Flag behaviour
    // ---------------------------------------------------------------------------

    #[Test]
    public function appendWritesOutboxRowWhenFlagEnabled(): void
    {
        ['store' => $inner, 'appended' => $appended] = $this->makeRecordingInnerStore();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $store = new OutboxAwareEventStore(
            $inner,
            $repo,
            $this->makeStubSerializer(),
            $this->makeNullMetrics(),
            featureFlag: new OutboxFeatureFlag(true),
        );

        $store->append('agg-1', $this->makeSingleEventStream());

        self::assertCount(1, $appended, 'Inner store must receive the append');
        self::assertCount(1, $log, 'Outbox repository must record one entry');
    }

    #[Test]
    public function appendSkipsOutboxWhenFlagDisabled(): void
    {
        ['store' => $inner, 'appended' => $appended] = $this->makeRecordingInnerStore();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $store = new OutboxAwareEventStore(
            $inner,
            $repo,
            $this->makeStubSerializer(),
            $this->makeNullMetrics(),
            featureFlag: new OutboxFeatureFlag(false),
        );

        $store->append('agg-1', $this->makeSingleEventStream());

        self::assertCount(1, $appended, 'Inner store MUST still receive the append (kill-switch does not disable event storage)');
        self::assertCount(0, $log, 'Outbox repository MUST NOT receive entries when flag is disabled');
    }

    #[Test]
    public function appendDefaultsToEnabledWhenNoFlagProvided(): void
    {
        ['store' => $inner, 'appended' => $appended] = $this->makeRecordingInnerStore();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        // Construct WITHOUT the optional $featureFlag argument — backwards-compat case.
        $store = new OutboxAwareEventStore(
            $inner,
            $repo,
            $this->makeStubSerializer(),
            $this->makeNullMetrics(),
        );

        $store->append('agg-1', $this->makeSingleEventStream());

        self::assertCount(1, $appended);
        self::assertCount(1, $log, 'Backwards-compat: default behaviour writes the outbox row');
        self::assertTrue($store->isEnabled(), 'Default state must be enabled when no flag is injected');
    }

    #[Test]
    public function runtimeDisableOverridesInitialFlagState(): void
    {
        ['store' => $inner] = $this->makeRecordingInnerStore();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $store = new OutboxAwareEventStore(
            $inner,
            $repo,
            $this->makeStubSerializer(),
            $this->makeNullMetrics(),
            featureFlag: new OutboxFeatureFlag(true),
        );

        $store->disable();
        $store->append('agg-1', $this->makeSingleEventStream());

        self::assertFalse($store->isEnabled());
        self::assertCount(0, $log, 'Runtime disable() must short-circuit outbox writes');
    }
}
