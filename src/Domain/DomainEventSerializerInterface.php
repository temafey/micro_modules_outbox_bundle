<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Domain;

use Broadway\Domain\DomainMessage;

/**
 * Serializes Broadway domain messages for outbox storage.
 *
 * Responsible for converting domain events into a format suitable
 * for outbox table storage and eventual publishing to message brokers.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.2-eventstore-decorator.md
 */
interface DomainEventSerializerInterface
{
    /**
     * Serializes a domain message to a JSON-encodable array payload.
     *
     * @return array<string, mixed>
     */
    public function serialize(DomainMessage $message): array;

    /**
     * Extracts the event type (class name) from a domain message.
     */
    public function extractEventType(DomainMessage $message): string;

    /**
     * Determines the target topic for the event.
     *
     * Used to route events to the appropriate message queue/topic.
     */
    public function determineTopicName(DomainMessage $message): string;

    /**
     * Determines the routing key for the event.
     *
     * Used for message broker routing (e.g., RabbitMQ routing key).
     */
    public function determineRoutingKey(DomainMessage $message): string;
}
