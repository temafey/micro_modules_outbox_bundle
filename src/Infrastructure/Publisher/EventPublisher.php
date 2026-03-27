<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Publisher;

use Broadway\Serializer\Serializable;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\EventQueue\Domain\EventHandling\QueueEventInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Publisher for domain events stored in the outbox.
 *
 * Deserializes event payloads and publishes through QueueEventInterface
 * to maintain compatibility with existing event processing infrastructure.
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.4: Background Publisher
 */
final class EventPublisher implements OutboxPublisherInterface
{
    /**
     * Registry of event classes for deserialization.
     *
     * @var array<string, class-string<Serializable>>
     */
    private array $eventClassMap = [];

    public function __construct(
        private readonly QueueEventInterface $queueEventProducer,
        private readonly ?QueueEventInterface $globalQueueEventProducer = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Register an event class for deserialization.
     *
     * @param string                     $eventType  The event type identifier
     * @param class-string<Serializable> $eventClass The event class FQCN
     */
    public function registerEventClass(string $eventType, string $eventClass): void
    {
        $this->eventClassMap[$eventType] = $eventClass;
    }

    /**
     * Register multiple event classes at once.
     *
     * @param array<string, class-string<Serializable>> $classMap Map of event type to class name
     */
    public function registerEventClasses(array $classMap): void
    {
        foreach ($classMap as $eventType => $eventClass) {
            $this->registerEventClass($eventType, $eventClass);
        }
    }

    public function publish(OutboxEntryInterface $entry): void
    {
        $eventType = $entry->getEventType();
        $payloadJson = $entry->getEventPayload();

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw OutboxPublishException::deserializationFailed(
                $eventType,
                'Invalid JSON payload: ' . $jsonException->getMessage(),
            );
        }

        // Extract the inner event payload from Broadway DomainMessage envelope
        // Structure: {uuid, payload: {class, payload: {actual_event_data}}, metadata, playhead, recorded_on}
        $eventPayload = $this->extractEventPayload($payload);

        // Resolve event class
        $eventClass = $this->resolveEventClass($eventType);

        // Deserialize the event using Broadway Serializable interface
        try {
            /** @var Serializable $event */
            $event = $eventClass::deserialize($eventPayload);
        } catch (\Throwable $throwable) {
            throw OutboxPublishException::deserializationFailed($eventType, $throwable->getMessage());
        }

        // Publish to internal queue
        $this->queueEventProducer->publishEventToQueue($event);

        // Publish to global/ACL queue (non-fatal — internal publish is primary)
        if ($this->globalQueueEventProducer !== null) {
            try {
                $this->globalQueueEventProducer->publishEventToQueue($event);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to publish event to global queue', [
                    'message_id' => $entry->getId(),
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->debug('Event published from outbox', [
            'message_id' => $entry->getId(),
            'event_type' => $eventType,
            'event_class' => $eventClass,
            'topic' => $entry->getTopic(),
            'aggregate_id' => $entry->getAggregateId(),
        ]);
    }

    public function supports(string $messageType): bool
    {
        return $messageType === OutboxMessageType::EVENT->value;
    }

    /**
     * Resolve the event class for deserialization.
     *
     * @return class-string<Serializable>
     */
    private function resolveEventClass(string $eventType): string
    {
        // Try registered map first
        if (isset($this->eventClassMap[$eventType])) {
            return $this->eventClassMap[$eventType];
        }

        // Try to use event type as class name directly (FQCN in event_type)
        if (class_exists($eventType) && is_subclass_of($eventType, Serializable::class)) {
            return $eventType;
        }

        throw OutboxPublishException::eventClassNotFound($eventType);
    }

    /**
     * Extract the inner event payload from Broadway DomainMessage envelope.
     *
     * Broadway serializes DomainMessage as:
     * {
     *   "uuid": "aggregate-uuid",
     *   "payload": {
     *     "class": "Fully\\Qualified\\EventClass",
     *     "payload": { actual event data }
     *   },
     *   "metadata": {...},
     *   "playhead": int,
     *   "recorded_on": "timestamp"
     * }
     *
     * This method extracts the inner "payload.payload" containing the actual event data.
     *
     * @param array<string, mixed> $envelope The full Broadway DomainMessage envelope
     *
     * @return array<string, mixed> The inner event payload
     */
    private function extractEventPayload(array $envelope): array
    {
        // Check if this is a Broadway envelope (has 'payload' with nested 'payload')
        if (! isset($envelope['payload']) || ! is_array($envelope['payload'])) {
            // Not a Broadway envelope, return as-is (direct event data)
            return $envelope;
        }

        $outerPayload = $envelope['payload'];

        // Check for nested payload structure (Broadway format)
        if (isset($outerPayload['payload']) && is_array($outerPayload['payload'])) {
            return $outerPayload['payload'];
        }

        // Outer payload doesn't have nested structure, might be direct event data
        // This handles cases where the event was serialized without Broadway wrapper
        return $outerPayload;
    }
}
