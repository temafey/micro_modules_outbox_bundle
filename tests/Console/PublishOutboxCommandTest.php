<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Console;

use MicroModule\Outbox\Console\PublishOutboxCommand;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\NullOutboxMetrics;
use MicroModule\Outbox\Infrastructure\OutboxEntry;
use MicroModule\Outbox\Infrastructure\Publisher\OutboxPublisherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PublishOutboxCommand::class)]
final class PublishOutboxCommandTest extends TestCase
{
    #[Test]
    public function runOnceWithNoMessagesReturnsSuccess(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 0,
            'total_events' => 0,
            'total_tasks' => 0,
            'failed_count' => 0,
            'oldest_pending_seconds' => null,
        ]);

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No pending messages', $tester->getDisplay());
    }

    #[Test]
    public function runOncePublishesMessages(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{"title":"Test"}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$entry]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 0,
            'oldest_pending_seconds' => 5,
        ]);
        $repository->expects(self::once())
            ->method('markAsPublished')
            ->with([$entry->getId()], self::isInstanceOf(\DateTimeImmutable::class));

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with($entry);

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Messages processed: 1', $tester->getDisplay());
    }

    #[Test]
    public function runOnceHandlesPublishFailure(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$entry]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 0,
            'oldest_pending_seconds' => 5,
        ]);
        $repository->expects(self::once())
            ->method('markAsFailed');

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Messages failed: 1', $tester->getDisplay());
    }

    #[Test]
    public function dryRunDoesNotPublishOrMark(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$entry]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 0,
            'oldest_pending_seconds' => 5,
        ]);
        $repository->expects(self::never())->method('markAsPublished');

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
    }

    #[Test]
    public function messageTypeFilterEventOnly(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findUnpublishedByType')
            ->with(OutboxMessageType::EVENT, self::anything())
            ->willReturn([]);
        $repository->expects(self::never())
            ->method('findUnpublished');
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 0,
            'total_events' => 0,
            'total_tasks' => 0,
            'failed_count' => 0,
            'oldest_pending_seconds' => null,
        ]);

        $publisher = $this->createMock(OutboxPublisherInterface::class);

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--message-type' => 'event']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function poisonMessageAtRetryBudgetIsExcludedFromPolling(): void
    {
        // Regression: previously findUnpublished returned a row whose retry_count had already
        // reached --max-retries; the publisher would re-fetch it every poll cycle, fail, but
        // skip markAsFailed (off-by-one in `< max_retries`), causing a tight loop. The fetch
        // filter now uses strict less-than, so such a row is skipped before publish.
        $exhausted = OutboxEntry::fromArray([
            'id' => '11111111-1111-1111-1111-111111111111',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-poison',
            'event_type' => 'NewsCreatedEvent',
            'event_payload' => '{}',
            'topic' => 'events.news',
            'routing_key' => 'event.news_created',
            'created_at' => '2026-01-01 00:00:00.000000',
            'published_at' => null,
            'retry_count' => 5,
            'last_error' => 'Connection refused',
            'next_retry_at' => '2026-01-01 00:00:32.000000',
            'sequence_number' => 1,
        ]);

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$exhausted]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 1,
            'oldest_pending_seconds' => 60,
        ]);
        $repository->expects(self::never())->method('markAsPublished');
        $repository->expects(self::never())->method('markAsFailed');

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--max-retries' => 5]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function failureAtBudgetMinusOneStillRecordsFailure(): void
    {
        // Regression: the previous implementation only called markAsFailed when retry_count was
        // strictly less than --max-retries. As a result, on the LAST allowed attempt
        // (retry_count == max_retries - 1) the failure was recorded — that part was already
        // correct — but what mattered was that the row's retry_count actually got incremented
        // so that subsequent polls would skip it via the fetch filter. This test pins that
        // behaviour: at retry_count = max_retries - 1 a failed publish must produce
        // exactly one markAsFailed call so retry_count crosses the threshold.
        $almostExhausted = OutboxEntry::fromArray([
            'id' => '22222222-2222-2222-2222-222222222222',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-last',
            'event_type' => 'NewsCreatedEvent',
            'event_payload' => '{}',
            'topic' => 'events.news',
            'routing_key' => 'event.news_created',
            'created_at' => '2026-01-01 00:00:00.000000',
            'published_at' => null,
            'retry_count' => 4,
            'last_error' => 'previous error',
            'next_retry_at' => '2026-01-01 00:00:00.000000',
            'sequence_number' => 2,
        ]);

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$almostExhausted]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 1,
            'oldest_pending_seconds' => 60,
        ]);
        $repository->expects(self::once())
            ->method('markAsFailed')
            ->with(
                $almostExhausted->getId(),
                self::isType('string'),
                self::isInstanceOf(\DateTimeImmutable::class),
            );

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--max-retries' => 5]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function failureAtBudgetMinusOneStampsDeadLetter(): void
    {
        // When retry_count crosses the budget, the publisher must call markAsDeadLetter
        // so the row is excluded from polling via SQL filter (dead_letter_at IS NULL).
        $almostExhausted = OutboxEntry::fromArray([
            'id' => '33333333-3333-3333-3333-333333333333',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-dlq',
            'event_type' => 'NewsCreatedEvent',
            'event_payload' => '{}',
            'topic' => 'events.news',
            'routing_key' => 'event.news_created',
            'created_at' => '2026-01-01 00:00:00.000000',
            'published_at' => null,
            'retry_count' => 4,
            'last_error' => 'previous',
            'next_retry_at' => '2026-01-01 00:00:00.000000',
            'sequence_number' => 3,
        ]);

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$almostExhausted]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 1,
            'dlq_count' => 0,
            'oldest_pending_seconds' => 60,
        ]);
        $repository->expects(self::once())->method('markAsFailed');
        $repository->expects(self::once())
            ->method('markAsDeadLetter')
            ->with($almostExhausted->getId(), self::isInstanceOf(\DateTimeImmutable::class));

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->method('publish')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--max-retries' => 5]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function failureBelowBudgetDoesNotStampDeadLetter(): void
    {
        // retry_count goes 0 -> 1 (still below 5); only markAsFailed is called.
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('findUnpublished')->willReturn([$entry]);
        $repository->method('getMetrics')->willReturn([
            'total_pending' => 1,
            'total_events' => 1,
            'total_tasks' => 0,
            'failed_count' => 0,
            'dlq_count' => 0,
            'oldest_pending_seconds' => 5,
        ]);
        $repository->expects(self::once())->method('markAsFailed');
        $repository->expects(self::never())->method('markAsDeadLetter');

        $publisher = $this->createMock(OutboxPublisherInterface::class);
        $publisher->method('publish')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--run-once' => true, '--max-retries' => 5]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function commandHasCorrectName(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $publisher = $this->createMock(OutboxPublisherInterface::class);

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());

        self::assertSame('app:outbox:publish', $command->getName());
    }
}
