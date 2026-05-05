<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Console;

use MicroModule\Outbox\Console\DlqOutboxCommand;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\OutboxEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DlqOutboxCommand::class)]
final class DlqOutboxCommandTest extends TestCase
{
    #[Test]
    public function listEmptyDlqShowsSuccess(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countDeadLetter')
            ->willReturn(0);
        $repository->expects(self::never())->method('findDeadLetter');

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('DLQ is empty', $tester->getDisplay());
    }

    #[Test]
    public function listShowsDlqEntriesInTable(): void
    {
        $entry = OutboxEntry::fromArray([
            'id' => '11111111-1111-1111-1111-111111111111',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-dlq',
            'event_type' => 'NewsCreatedEvent',
            'event_payload' => '{}',
            'topic' => 'events.news',
            'routing_key' => 'event.news_created',
            'created_at' => '2026-05-05 00:00:00.000000',
            'published_at' => null,
            'retry_count' => 5,
            'last_error' => 'Connection refused',
            'next_retry_at' => null,
            'sequence_number' => 1,
            'dead_letter_at' => '2026-05-05 01:00:00.000000',
        ]);

        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('countDeadLetter')->willReturn(1);
        $repository->expects(self::once())
            ->method('findDeadLetter')
            ->with(50)
            ->willReturn([$entry]);

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('11111111-1111-1111-1111-111111111111', $tester->getDisplay());
        self::assertStringContainsString('NewsCreatedEvent', $tester->getDisplay());
    }

    #[Test]
    public function limitOptionForwardedToFindDeadLetter(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('countDeadLetter')->willReturn(1);
        $repository->expects(self::once())
            ->method('findDeadLetter')
            ->with(10)
            ->willReturn([]);

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute(['--limit' => 10]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function countOnlyOptionPrintsNumber(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countDeadLetter')
            ->willReturn(42);
        $repository->expects(self::never())->method('findDeadLetter');

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute(['--count' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('42', $tester->getDisplay());
    }

    #[Test]
    public function replayCallsRepositoryAndReturnsSuccess(): void
    {
        $id = '22222222-2222-2222-2222-222222222222';
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('replayDeadLetter')
            ->with($id)
            ->willReturn(true);

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute(['--replay' => $id]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Replay scheduled', $tester->getDisplay());
    }

    #[Test]
    public function replayUnknownIdReturnsFailure(): void
    {
        $id = '33333333-3333-3333-3333-333333333333';
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('replayDeadLetter')->with($id)->willReturn(false);

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute(['--replay' => $id]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No DLQ row matched', $tester->getDisplay());
    }

    #[Test]
    public function replayPropagatesRepositoryFailureAsCommandFailure(): void
    {
        $id = '44444444-4444-4444-4444-444444444444';
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->method('replayDeadLetter')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $command = new DlqOutboxCommand($repository);
        $tester = new CommandTester($command);

        $tester->execute(['--replay' => $id]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DB connection lost', $tester->getDisplay());
    }

    #[Test]
    public function commandHasCorrectName(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $command = new DlqOutboxCommand($repository);

        self::assertSame('app:outbox:dlq', $command->getName());
    }
}
