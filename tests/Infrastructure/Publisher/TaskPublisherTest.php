<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure\Publisher;

use Broadway\CommandHandling\CommandBus;
use Broadway\CommandHandling\CommandHandler;
use Broadway\Serializer\Serializable;
use MicroModule\Outbox\Application\Task\CommandFactory;
use MicroModule\Outbox\Infrastructure\OutboxEntry;
use MicroModule\Outbox\Infrastructure\Publisher\OutboxPublishException;
use MicroModule\Outbox\Infrastructure\Publisher\TaskPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Inline test doubles (shared with CommandFactoryTest via same namespace)
// ---------------------------------------------------------------------------

// Re-use the same Serializable command stub pattern — defined here locally
// to keep this test file self-contained.

final class SerializableTaskCommand implements Serializable
{
    public function __construct(public readonly string $id = '')
    {
    }

    /** @param array<string, mixed> $data */
    public static function deserialize(array $data): self
    {
        return new self($data['id'] ?? '');
    }

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return ['id' => $this->id];
    }
}

// ---------------------------------------------------------------------------
// Recording CommandBus fake
// ---------------------------------------------------------------------------

final class RecordingCommandBus implements CommandBus
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch($command): void
    {
        $this->dispatched[] = $command;
    }

    public function subscribe(CommandHandler $handler): void
    {
        // no-op in tests
    }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

#[CoversClass(TaskPublisher::class)]
final class TaskPublisherTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    #[Test]
    public function validTaskEntryDeserializesAndDispatchesCommand(): void
    {
        $commandBus = new RecordingCommandBus();
        $factory = new CommandFactory([
            SerializableTaskCommand::class => SerializableTaskCommand::class,
        ]);
        $publisher = new TaskPublisher($commandBus, $factory);

        $entry = OutboxEntry::createForTask(
            aggregateType: 'News',
            aggregateId: 'agg-uuid',
            commandType: SerializableTaskCommand::class,
            commandPayload: json_encode(['id' => 'cmd-1'], JSON_THROW_ON_ERROR),
            topic: 'tasks.news',
            routingKey: 'task.create_news',
        );

        $publisher->publish($entry);

        self::assertCount(1, $commandBus->dispatched);
        self::assertInstanceOf(SerializableTaskCommand::class, $commandBus->dispatched[0]);
        self::assertSame('cmd-1', $commandBus->dispatched[0]->id);
    }

    // -----------------------------------------------------------------------
    // JSON error handling
    // -----------------------------------------------------------------------

    #[Test]
    public function malformedJsonPayloadThrowsDeserializationFailedException(): void
    {
        $commandBus = new RecordingCommandBus();
        $factory = new CommandFactory();
        $publisher = new TaskPublisher($commandBus, $factory);

        $entry = OutboxEntry::createForTask(
            aggregateType: 'News',
            aggregateId: 'agg-uuid',
            commandType: SerializableTaskCommand::class,
            commandPayload: '{invalid json',
            topic: 'tasks.news',
            routingKey: 'task.create_news',
        );

        $this->expectException(OutboxPublishException::class);
        $this->expectExceptionMessage('Failed to deserialize');

        $publisher->publish($entry);
    }

    // -----------------------------------------------------------------------
    // CommandFactory error handling
    // -----------------------------------------------------------------------

    #[Test]
    public function commandFactoryFailureIsRethrownAsDeserializationFailedException(): void
    {
        $commandBus = new RecordingCommandBus();
        // Empty factory — FQCN fallback will fail because SerializableTaskCommand
        // IS serializable, but we force the issue by using a non-existent class name.
        $factory = new CommandFactory();
        $publisher = new TaskPublisher($commandBus, $factory);

        $entry = OutboxEntry::createForTask(
            aggregateType: 'News',
            aggregateId: 'agg-uuid',
            commandType: 'NonExistent\\Command\\Ghost',
            commandPayload: json_encode(['id' => 'x'], JSON_THROW_ON_ERROR),
            topic: 'tasks.news',
            routingKey: 'task.create_news',
        );

        $this->expectException(OutboxPublishException::class);
        $this->expectExceptionMessage('Failed to deserialize');

        $publisher->publish($entry);
    }

    // -----------------------------------------------------------------------
    // supports()
    // -----------------------------------------------------------------------

    #[Test]
    public function supportsReturnsTrueForTaskMessageType(): void
    {
        $publisher = new TaskPublisher(new RecordingCommandBus(), new CommandFactory());

        self::assertTrue($publisher->supports('TASK'));
    }

    #[Test]
    public function supportsReturnsFalseForEventMessageType(): void
    {
        $publisher = new TaskPublisher(new RecordingCommandBus(), new CommandFactory());

        self::assertFalse($publisher->supports('EVENT'));
    }
}
