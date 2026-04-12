<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Application\Task;

use Broadway\Serializer\Serializable;

/**
 * Factory for deserializing command objects from outbox TASK payloads.
 *
 * Mirrors the event-class resolution pattern used in EventPublisher:
 *  1. Resolve via registered alias (classMap).
 *  2. Fall back to treating the given string as a FQCN.
 *  3. Validate Broadway Serializable contract before calling deserialize().
 *
 * Registration is validated eagerly at container build time (inside
 * registerCommandClass()) so misconfigured class maps surface during
 * warmup rather than at runtime.
 *
 * @see \MicroModule\Outbox\Infrastructure\Publisher\TaskPublisher
 * @see TASK-01-10: VP-7b — CommandFactory for TaskPublisher
 */
final class CommandFactory
{
    /** @var array<string, class-string<Serializable>> */
    private array $commandClassMap = [];

    /**
     * @param array<string, class-string<Serializable>> $classMap Map of alias/type → FQCN
     */
    public function __construct(array $classMap = [])
    {
        foreach ($classMap as $alias => $class) {
            $this->registerCommandClass($alias, $class);
        }
    }

    /**
     * Register a command class for alias-based deserialization.
     *
     * Throws \InvalidArgumentException immediately if the class does not
     * implement Broadway\Serializer\Serializable so that misconfiguration is
     * caught at container build time rather than during a live publish cycle.
     *
     * @param class-string<Serializable> $commandClass
     *
     * @throws \InvalidArgumentException when $commandClass does not implement Serializable
     */
    public function registerCommandClass(string $alias, string $commandClass): void
    {
        if (!is_subclass_of($commandClass, Serializable::class)) {
            throw new \InvalidArgumentException(sprintf(
                'Command class %s must implement Broadway\\Serializer\\Serializable.',
                $commandClass,
            ));
        }

        $this->commandClassMap[$alias] = $commandClass;
    }

    /**
     * Deserialize a command from an outbox TASK payload.
     *
     * Resolution order:
     *  1. Registered alias in classMap → use mapped FQCN.
     *  2. $commandClassName treated as FQCN directly (fallback).
     *
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException when the resolved class does not exist or does not implement Serializable
     */
    public function deserialize(string $commandClassName, array $payload): object
    {
        $resolvedClass = $this->commandClassMap[$commandClassName] ?? $commandClassName;

        if (!class_exists($resolvedClass)) {
            throw new \RuntimeException(sprintf('Command class not found: %s', $resolvedClass));
        }

        if (!is_subclass_of($resolvedClass, Serializable::class)) {
            throw new \RuntimeException(sprintf(
                'Command class %s must implement Broadway\\Serializer\\Serializable.',
                $resolvedClass,
            ));
        }

        /** @var class-string<Serializable>&callable(array<string, mixed>):object $resolvedClass */
        return $resolvedClass::deserialize($payload);
    }
}
