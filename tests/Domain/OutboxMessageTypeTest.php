<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Domain;

use MicroModule\Outbox\Domain\OutboxMessageType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxMessageType::class)]
final class OutboxMessageTypeTest extends TestCase
{
    #[Test]
    public function eventCaseHasCorrectValue(): void
    {
        self::assertSame('EVENT', OutboxMessageType::EVENT->value);
    }

    #[Test]
    public function taskCaseHasCorrectValue(): void
    {
        self::assertSame('TASK', OutboxMessageType::TASK->value);
    }

    #[Test]
    public function eventLabelReturnsHumanReadable(): void
    {
        self::assertSame('Domain Event', OutboxMessageType::EVENT->label());
    }

    #[Test]
    public function taskLabelReturnsHumanReadable(): void
    {
        self::assertSame('Task Command', OutboxMessageType::TASK->label());
    }

    #[Test]
    public function isEventReturnsTrueForEvent(): void
    {
        self::assertTrue(OutboxMessageType::EVENT->isEvent());
        self::assertFalse(OutboxMessageType::TASK->isEvent());
    }

    #[Test]
    public function isTaskReturnsTrueForTask(): void
    {
        self::assertTrue(OutboxMessageType::TASK->isTask());
        self::assertFalse(OutboxMessageType::EVENT->isTask());
    }

    #[Test]
    public function fromStringCreatesCorrectCase(): void
    {
        self::assertSame(OutboxMessageType::EVENT, OutboxMessageType::from('EVENT'));
        self::assertSame(OutboxMessageType::TASK, OutboxMessageType::from('TASK'));
    }

    #[Test]
    public function fromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        OutboxMessageType::from('INVALID');
    }

    #[Test]
    public function enumHasExactlyTwoCases(): void
    {
        self::assertCount(2, OutboxMessageType::cases());
    }
}
