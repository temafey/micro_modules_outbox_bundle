<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Application\Task;

use Broadway\Serializer\Serializable;
use MicroModule\Outbox\Application\Task\CommandFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Inline test doubles
// ---------------------------------------------------------------------------

/**
 * A valid Broadway-Serializable command stub used in tests.
 */
final class StubSerializableCommand implements Serializable
{
    public function __construct(public readonly string $value = 'default')
    {
    }

    /** @param array<string, mixed> $data */
    public static function deserialize(array $data): self
    {
        return new self($data['value'] ?? 'default');
    }

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return ['value' => $this->value];
    }
}

/**
 * A class that does NOT implement Serializable — used for negative tests.
 */
final class NonSerializableCommand
{
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

#[CoversClass(CommandFactory::class)]
final class CommandFactoryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Registration tests
    // -----------------------------------------------------------------------

    #[Test]
    public function registerCommandClassThrowsForNonSerializableClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement Broadway\\Serializer\\Serializable');

        $factory = new CommandFactory();
        $factory->registerCommandClass('alias', NonSerializableCommand::class);
    }

    #[Test]
    public function constructorThrowsForNonSerializableClassInMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement Broadway\\Serializer\\Serializable');

        new CommandFactory(['alias' => NonSerializableCommand::class]);
    }

    // -----------------------------------------------------------------------
    // Deserialization — happy path
    // -----------------------------------------------------------------------

    #[Test]
    public function deserializeByAliasReturnsCorrectInstance(): void
    {
        $factory = new CommandFactory([
            'stub.command' => StubSerializableCommand::class,
        ]);

        $result = $factory->deserialize('stub.command', ['value' => 'hello']);

        self::assertInstanceOf(StubSerializableCommand::class, $result);
        self::assertSame('hello', $result->value);
    }

    #[Test]
    public function deserializeByFqcnFallbackReturnsCorrectInstance(): void
    {
        $factory = new CommandFactory(); // nothing registered

        $result = $factory->deserialize(StubSerializableCommand::class, ['value' => 'world']);

        self::assertInstanceOf(StubSerializableCommand::class, $result);
        self::assertSame('world', $result->value);
    }

    // -----------------------------------------------------------------------
    // Deserialization — error cases
    // -----------------------------------------------------------------------

    #[Test]
    public function deserializeNonExistentClassThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command class not found');

        $factory = new CommandFactory();
        $factory->deserialize('NonExistent\\Command\\DoesNotExist', []);
    }

    #[Test]
    public function deserializeClassNotImplementingSerializableThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement Broadway\\Serializer\\Serializable');

        $factory = new CommandFactory();
        // Use the FQCN directly (bypass registration validation) to test the
        // deserialize() guard path independently.
        $factory->deserialize(NonSerializableCommand::class, []);
    }
}
