<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Domain;

/**
 * Discriminator enum for outbox message types.
 *
 * Distinguishes between domain events (from EventStore) and
 * task commands (from TaskRepository) stored in the outbox table.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.1-database-core-components.md
 */
enum OutboxMessageType: string
{
    /**
     * Domain event message type.
     *
     * Used for events stored via OutboxAwareEventStore decorator.
     * These are published to the event bus for projectors and sagas.
     */
    case EVENT = 'EVENT';

    /**
     * Task command message type.
     *
     * Used for commands stored via OutboxAwareTaskRepository decorator.
     * These are published to command queues for async processing.
     */
    case TASK = 'TASK';

    /**
     * Get human-readable label for the message type.
     */
    public function label(): string
    {
        return match ($this) {
            self::EVENT => 'Domain Event',
            self::TASK => 'Task Command',
        };
    }

    /**
     * Check if this is an event type message.
     */
    public function isEvent(): bool
    {
        return $this === self::EVENT;
    }

    /**
     * Check if this is a task type message.
     */
    public function isTask(): bool
    {
        return $this === self::TASK;
    }
}
