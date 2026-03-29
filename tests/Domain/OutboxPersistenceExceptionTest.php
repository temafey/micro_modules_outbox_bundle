<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Domain;

use MicroModule\Outbox\Domain\OutboxPersistenceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxPersistenceException::class)]
final class OutboxPersistenceExceptionTest extends TestCase
{
    #[Test]
    public function saveFailedContainsEntryIdAndPreviousException(): void
    {
        $previous = new \RuntimeException('Connection lost');
        $exception = OutboxPersistenceException::saveFailed('entry-123', $previous);

        self::assertStringContainsString('entry-123', $exception->getMessage());
        self::assertStringContainsString('Connection lost', $exception->getMessage());
        self::assertSame('entry-123', $exception->getEntryId());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function batchSaveFailedContainsCountAndPreviousException(): void
    {
        $previous = new \RuntimeException('Deadlock');
        $exception = OutboxPersistenceException::batchSaveFailed(5, $previous);

        self::assertStringContainsString('5', $exception->getMessage());
        self::assertStringContainsString('Deadlock', $exception->getMessage());
        self::assertNull($exception->getEntryId());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function updateFailedContainsEntryIdAndPreviousException(): void
    {
        $previous = new \RuntimeException('Timeout');
        $exception = OutboxPersistenceException::updateFailed('entry-456', $previous);

        self::assertStringContainsString('entry-456', $exception->getMessage());
        self::assertStringContainsString('Timeout', $exception->getMessage());
        self::assertSame('entry-456', $exception->getEntryId());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function notFoundContainsEntryId(): void
    {
        $exception = OutboxPersistenceException::notFound('entry-789');

        self::assertStringContainsString('entry-789', $exception->getMessage());
        self::assertSame('entry-789', $exception->getEntryId());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function exceptionExtendsRuntimeException(): void
    {
        $exception = OutboxPersistenceException::notFound('x');

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
