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
    public function commandHasCorrectName(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $publisher = $this->createMock(OutboxPublisherInterface::class);

        $command = new PublishOutboxCommand($repository, $publisher, new NullOutboxMetrics());

        self::assertSame('app:outbox:publish', $command->getName());
    }
}
