<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

use Enqueue\Client\ProducerInterface;

/**
 * Marker interface for outbox-aware producer decorator.
 *
 * Extends ProducerInterface to maintain compatibility while adding
 * outbox awareness for transactional messaging. Implementations intercept
 * sendCommand() calls and write to the outbox table instead of sending
 * directly to the message broker.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.3-taskrepository-decorator.md
 */
interface OutboxAwareProducerInterface extends ProducerInterface
{
    /**
     * Check if outbox mode is enabled.
     *
     * When disabled, commands pass through directly to the inner producer.
     */
    public function isOutboxEnabled(): bool;

    /**
     * Enable outbox mode.
     *
     * All subsequent sendCommand() calls will write to outbox.
     */
    public function enable(): void;

    /**
     * Disable outbox mode.
     *
     * All subsequent sendCommand() calls will bypass outbox and send directly.
     */
    public function disable(): void;
}
