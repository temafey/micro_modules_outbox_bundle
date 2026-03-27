<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Publisher;

/**
 * Exception thrown when outbox message publishing fails.
 *
 * @see TASK-14.4: Background Publisher
 */
final class OutboxPublishException extends \RuntimeException
{
    /**
     * Create exception for missing event class mapping.
     */
    public static function eventClassNotFound(string $eventType): self
    {
        return new self(sprintf(
            'Cannot resolve event class for type: %s. Register it via registerEventClass().',
            $eventType,
        ));
    }

    /**
     * Create exception for invalid task message format.
     */
    public static function invalidTaskFormat(string $messageId, string $details): self
    {
        return new self(sprintf('Invalid task message format for outbox entry %s: %s', $messageId, $details));
    }

    /**
     * Create exception for unsupported message type.
     */
    public static function unsupportedMessageType(string $messageType): self
    {
        return new self(sprintf('Unsupported message type: %s. Expected "event" or "task".', $messageType));
    }

    /**
     * Create exception for deserialization failure.
     */
    public static function deserializationFailed(string $eventType, string $reason): self
    {
        return new self(sprintf('Failed to deserialize event %s: %s', $eventType, $reason));
    }
}
