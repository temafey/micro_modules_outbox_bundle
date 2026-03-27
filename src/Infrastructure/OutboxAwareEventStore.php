<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainMessage;
use Broadway\EventStore\EventStore;
use MicroModule\Outbox\Domain\DomainEventSerializerInterface;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decorator that intercepts event storage and writes to the outbox table.
 *
 * This decorator wraps Broadway's EventStore and ensures that for every
 * event appended to the event store, a corresponding outbox entry is
 * created within the same database transaction.
 *
 * The decorator is transparent to the rest of the application - it
 * implements the same EventStore interface and delegates all operations
 * to the inner store.
 *
 * IMPORTANT: This decorator assumes it operates within a database transaction.
 * The caller (typically EventSourcingRepository) must manage the transaction.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.2-eventstore-decorator.md
 */
final class OutboxAwareEventStore implements EventStore
{
    private bool $enabled = true;

    public function __construct(
        private readonly EventStore $inner,
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly DomainEventSerializerInterface $serializer,
        private readonly OutboxMetricsInterface $metrics,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Appends events to the event store and creates outbox entries.
     *
     * This method first delegates to the inner event store, then creates
     * outbox entries for each event in the stream. Both operations occur
     * within the same transaction for atomicity.
     *
     * @param mixed             $id          The aggregate root ID
     * @param DomainEventStream $eventStream The stream of domain events
     */
    public function append(mixed $id, DomainEventStream $eventStream): void
    {
        // First, delegate to the inner event store
        $this->inner->append($id, $eventStream);

        // If outbox is disabled (e.g., during migration), skip
        if (! $this->enabled) {
            $this->logger->debug('Outbox disabled, skipping outbox entry creation', [
                'aggregate_id' => (string) $id,
            ]);

            return;
        }

        // Create outbox entries for each event in the stream
        $outboxEntries = $this->createOutboxEntries($id, $eventStream);

        if ($outboxEntries !== []) {
            $this->outboxRepository->saveAll($outboxEntries);

            // Record metrics for each enqueued message
            foreach ($outboxEntries as $entry) {
                $this->metrics->recordMessageEnqueued(OutboxMessageType::EVENT, $entry->getAggregateType());
            }

            $this->logger->debug('Created outbox entries for domain events', [
                'aggregate_id' => (string) $id,
                'entry_count' => count($outboxEntries),
            ]);
        }
    }

    /**
     * Loads events from the event store.
     *
     * Delegated to inner store without modification.
     *
     * @param mixed $id The aggregate root ID
     *
     * @return DomainEventStream The event stream for the aggregate
     */
    public function load(mixed $id): DomainEventStream
    {
        return $this->inner->load($id);
    }

    /**
     * Loads events from a specific playhead.
     *
     * Delegated to inner store without modification.
     *
     * @param mixed $id       The aggregate root ID
     * @param int   $playhead The playhead to start loading from
     *
     * @return DomainEventStream The event stream from the playhead
     */
    public function loadFromPlayhead(mixed $id, int $playhead): DomainEventStream
    {
        return $this->inner->loadFromPlayhead($id, $playhead);
    }

    /**
     * Disables outbox writing.
     *
     * Useful for migrations or special cases where events should
     * be stored without creating outbox entries.
     */
    public function disable(): void
    {
        $this->enabled = false;
        $this->logger->info('Outbox event store decorator disabled');
    }

    /**
     * Enables outbox writing.
     */
    public function enable(): void
    {
        $this->enabled = true;
        $this->logger->info('Outbox event store decorator enabled');
    }

    /**
     * Returns whether outbox writing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Creates outbox entries for all events in the stream.
     *
     * @param mixed             $aggregateId The aggregate root ID
     * @param DomainEventStream $eventStream The stream of domain events
     *
     * @return OutboxEntryInterface[]
     */
    private function createOutboxEntries(mixed $aggregateId, DomainEventStream $eventStream): array
    {
        $entries = [];

        /** @var DomainMessage $message */
        foreach ($eventStream as $message) {
            $entries[] = $this->createOutboxEntry($aggregateId, $message);
        }

        return $entries;
    }

    /**
     * Creates a single outbox entry from a domain message.
     *
     * @param mixed         $aggregateId The aggregate root ID
     * @param DomainMessage $message     The domain message to convert
     *
     * @return OutboxEntryInterface The outbox entry
     */
    private function createOutboxEntry(mixed $aggregateId, DomainMessage $message): OutboxEntryInterface
    {
        $aggregateType = $this->extractAggregateType($message);
        $eventType = $this->serializer->extractEventType($message);
        $payload = $this->serializer->serialize($message);
        $topic = $this->serializer->determineTopicName($message);
        $routingKey = $this->serializer->determineRoutingKey($message);

        // JSON encode the payload for storage
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        return OutboxEntry::createForEvent(
            aggregateType: $aggregateType,
            aggregateId: (string) $aggregateId,
            eventType: $eventType,
            eventPayload: $payloadJson,
            topic: $topic,
            routingKey: $routingKey,
            sequenceNumber: $message->getPlayhead(),
        );
    }

    /**
     * Extracts aggregate type from the domain message.
     *
     * Tries to get from metadata first, falls back to extracting
     * from the event class namespace.
     *
     * @param DomainMessage $message The domain message
     *
     * @return string The aggregate type name
     */
    private function extractAggregateType(DomainMessage $message): string
    {
        $metadata = $message->getMetadata()
            ->serialize();

        // Try to get from metadata first (if set by aggregate)
        if (isset($metadata['aggregate_type'])) {
            return $metadata['aggregate_type'];
        }

        // Extract from event class namespace: News\Domain\Event\NewsCreated -> News
        $eventClass = $message->getPayload()::class;
        $parts = explode('\\', $eventClass);

        return $parts[0] ?? 'Unknown';
    }
}
