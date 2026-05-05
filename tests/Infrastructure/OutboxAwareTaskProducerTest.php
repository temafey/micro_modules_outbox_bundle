<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure;

use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\OutboxAwareTaskProducer;
use MicroModule\Saga\Application\SagaCommandQueueInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxAwareTaskProducer::class)]
final class OutboxAwareTaskProducerTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a recording fake for OutboxRepositoryInterface.
     *
     * Uses \ArrayObject so that the shared reference persists across method
     * boundaries without relying on PHP's array-reference-in-array-destructuring
     * (which does not maintain references correctly in all PHP 8.x contexts).
     *
     * @return array{repo: OutboxRepositoryInterface, log: \ArrayObject<int,OutboxEntryInterface>}
     */
    private function makeRecordingRepository(): array
    {
        $log = new \ArrayObject();

        $repo = new class ($log) implements OutboxRepositoryInterface {
            /** @param \ArrayObject<int,OutboxEntryInterface> $log */
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

            public function markAsDeadLetter(string $id, \DateTimeImmutable $deadLetterAt): void
            {
            }

            public function findDeadLetter(int $limit): array
            {
                return [];
            }

            public function countDeadLetter(): int
            {
                return 0;
            }

            public function replayDeadLetter(string $id): bool
            {
                return false;
            }

            public function countDeadLetterBefore(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function deleteDeadLetterBefore(\DateTimeImmutable $before, int $limit): int
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
                    'dlq_count' => 0,
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

    /**
     * Build a recording fake inner ProducerInterface.
     *
     * Uses \ArrayObject so that recorded calls survive method-boundary returns
     * without relying on PHP array references.
     *
     * @return array{producer: \Enqueue\Client\ProducerInterface, calls: \ArrayObject<int,array{command:string,message:mixed}>}
     */
    private function makeRecordingProducer(): array
    {
        $calls = new \ArrayObject();

        $producer = new class ($calls) implements \Enqueue\Client\ProducerInterface {
            /** @param \ArrayObject<int,array{command:string,message:mixed}> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function sendCommand(string $command, $message, bool $needReply = false): ?\Enqueue\Rpc\Promise
            {
                $this->calls->append(['command' => $command, 'message' => $message]);

                return null;
            }

            public function sendEvent(string $topic, $message): void
            {
                // intentionally no-op
            }
        };

        return ['producer' => $producer, 'calls' => $calls];
    }

    // ---------------------------------------------------------------------------
    // Interface contract
    // ---------------------------------------------------------------------------

    #[Test]
    public function implementsSagaCommandQueueInterface(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        self::assertInstanceOf(SagaCommandQueueInterface::class, $producer);
    }

    // ---------------------------------------------------------------------------
    // enqueue() — happy path
    // ---------------------------------------------------------------------------

    #[Test]
    public function enqueueWritesTaskOutboxEntry(): void
    {
        ['producer' => $innerProducer, 'calls' => $calls] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        $producer->enqueue('Foo\BarCommand', ['field' => 'value']);

        self::assertCount(1, $log, 'Exactly one outbox entry must be saved');
        self::assertCount(0, $calls, 'Inner producer must NOT be called by enqueue()');

        $entry = $log[0];
        self::assertSame(OutboxMessageType::TASK, $entry->getMessageType());
    }

    #[Test]
    public function enqueueStoresCorrectCommandType(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        $producer->enqueue('App\Command\CreateNewsCommand', ['title' => 'Hello']);

        $entry = $log[0];
        // getEventType() maps to task_type / commandType in OutboxEntry::createForTask()
        self::assertSame('App\Command\CreateNewsCommand', $entry->getEventType());
    }

    #[Test]
    public function enqueueStoresJsonEncodedPayload(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        $payload = ['field' => 'value', 'count' => 42];
        $producer->enqueue('Foo\BarCommand', $payload);

        $entry = $log[0];
        $decoded = json_decode($entry->getEventPayload(), true);

        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function enqueueDoesNotWriteToInnerProducer(): void
    {
        ['producer' => $innerProducer, 'calls' => $calls] = $this->makeRecordingProducer();
        ['repo' => $repo] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);
        $producer->enqueue('Foo\BarCommand', ['x' => 1]);

        self::assertCount(0, $calls, 'enqueue() must write to outbox only — not inner producer');
    }

    // ---------------------------------------------------------------------------
    // enqueue() — edge cases
    // ---------------------------------------------------------------------------

    #[Test]
    public function enqueueHandlesEmptyPayload(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);
        $producer->enqueue('Foo\BarCommand', []);

        self::assertCount(1, $log);
        self::assertSame('[]', $log[0]->getEventPayload());
    }

    #[Test]
    public function enqueueHandlesNestedPayload(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        $payload = ['nested' => ['key' => 'val'], 'list' => [1, 2, 3]];
        $producer->enqueue('Foo\BarCommand', $payload);

        $decoded = json_decode($log[0]->getEventPayload(), true);
        self::assertSame($payload, $decoded);
    }

    #[Test]
    public function enqueueMultipleCommandsCreatesMultipleEntries(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        $producer->enqueue('Cmd\CreateFoo', ['id' => '1']);
        $producer->enqueue('Cmd\UpdateFoo', ['id' => '1', 'name' => 'bar']);

        self::assertCount(2, $log);
        self::assertSame('Cmd\CreateFoo', $log[0]->getEventType());
        self::assertSame('Cmd\UpdateFoo', $log[1]->getEventType());
    }

    // ---------------------------------------------------------------------------
    // Backward compatibility — existing public API must not be removed
    // ---------------------------------------------------------------------------

    #[Test]
    public function sendCommandStillExistsForBackwardCompat(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        self::assertTrue(method_exists($producer, 'sendCommand'));
    }

    #[Test]
    public function sendEventStillExistsForBackwardCompat(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);

        self::assertTrue(method_exists($producer, 'sendEvent'));
    }

    #[Test]
    public function sendCommandWhenDisabledDelegatesToInnerProducer(): void
    {
        ['producer' => $innerProducer, 'calls' => $calls] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo, enabled: false);
        $producer->sendCommand('job_command_bus', ['type' => 'news.create']);

        self::assertCount(1, $calls, 'Inner producer must be called when outbox is disabled');
        self::assertCount(0, $log, 'Outbox must NOT be written when disabled');
    }

    #[Test]
    public function enqueueEntryHasNonEmptyId(): void
    {
        ['producer' => $innerProducer] = $this->makeRecordingProducer();
        ['repo' => $repo, 'log' => $log] = $this->makeRecordingRepository();

        $producer = new OutboxAwareTaskProducer($innerProducer, $repo);
        $producer->enqueue('Foo\BarCommand', ['field' => 'value']);

        self::assertNotEmpty($log[0]->getId());
    }
}
