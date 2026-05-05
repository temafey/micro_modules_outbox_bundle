<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Console;

use MicroModule\Outbox\Console\CleanupOutboxCommand;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\NullOutboxMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CleanupOutboxCommand::class)]
final class CleanupOutboxCommandTest extends TestCase
{
    #[Test]
    public function dryRunShowsCountWithoutDeleting(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countPublishedBefore')
            ->willReturn(42);
        $repository->expects(self::never())
            ->method('deletePublishedBefore');

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('42', $tester->getDisplay());
    }

    #[Test]
    public function dryRunWithIncludeFailedShowsBothCounts(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countPublishedBefore')
            ->willReturn(10);
        $repository->expects(self::once())
            ->method('countFailedExceedingRetries')
            ->with(5)
            ->willReturn(3);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true, '--include-failed' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('13', $tester->getDisplay());
    }

    #[Test]
    public function executionDeletesInBatches(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::exactly(2))
            ->method('deletePublishedBefore')
            ->willReturnOnConsecutiveCalls(1000, 500);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--retention' => '7', '--batch-size' => '1000']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1500', $tester->getDisplay());
    }

    #[Test]
    public function executionWithIncludeFailedDeletesBoth(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deletePublishedBefore')
            ->willReturn(100);
        $repository->expects(self::once())
            ->method('deleteFailedExceedingRetries')
            ->willReturn(5);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--include-failed' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function maxRetriesOptionIsForwardedToRepository(): void
    {
        // Regression: previously the cleanup command hard-coded `5` when calling
        // count/deleteFailedExceedingRetries, which made the option drift away from the
        // publisher --max-retries setting. Now --max-retries is threaded through.
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deletePublishedBefore')
            ->willReturn(0);
        $repository->expects(self::once())
            ->method('deleteFailedExceedingRetries')
            ->with(7, self::isType('int'))
            ->willReturn(0);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--include-failed' => true, '--max-retries' => 7]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function deadLetterRetentionDryRunCountsDlqRows(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countPublishedBefore')
            ->willReturn(2);
        $repository->expects(self::once())
            ->method('countDeadLetterBefore')
            ->with(self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(7);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true, '--dead-letter-retention' => 90]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('9', $tester->getDisplay());
    }

    #[Test]
    public function deadLetterRetentionExecutionDeletesDlqRows(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deletePublishedBefore')
            ->willReturn(0);
        $repository->expects(self::once())
            ->method('deleteDeadLetterBefore')
            ->with(self::isInstanceOf(\DateTimeImmutable::class), self::isType('int'))
            ->willReturn(3);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--dead-letter-retention' => 30]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('DLQ rows deleted: 3', $tester->getDisplay());
    }

    #[Test]
    public function deadLetterRetentionOmittedSkipsDlqCleanup(): void
    {
        // Without --dead-letter-retention, DLQ rows must be left alone.
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deletePublishedBefore')
            ->willReturn(0);
        $repository->expects(self::never())->method('deleteDeadLetterBefore');
        $repository->expects(self::never())->method('countDeadLetterBefore');

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function executionFailsOnException(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deletePublishedBefore')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DB connection lost', $tester->getDisplay());
    }

    #[Test]
    public function commandHasCorrectName(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());

        self::assertSame('app:outbox:cleanup', $command->getName());
    }

    #[Test]
    public function customRetentionDaysOption(): void
    {
        $repository = $this->createMock(OutboxRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countPublishedBefore')
            ->willReturn(0);

        $command = new CleanupOutboxCommand($repository, new NullOutboxMetrics());
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true, '--retention' => '30']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('30 days', $tester->getDisplay());
    }
}
