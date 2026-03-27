<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use Ramsey\Uuid\Uuid;

/**
 * Immutable value object representing an outbox entry.
 *
 * Stores domain events and task commands for reliable asynchronous
 * publishing via the transactional outbox pattern.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.1-database-core-components.md
 */
final readonly class OutboxEntry implements OutboxEntryInterface
{
    private const int MAX_RETRY_COUNT = 10;

    private const int DEFAULT_SEQUENCE = 0;

    private function __construct(
        private string $id,
        private OutboxMessageType $messageType,
        private string $aggregateType,
        private string $aggregateId,
        private string $eventType,
        private string $eventPayload,
        private string $topic,
        private string $routingKey,
        private \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $publishedAt,
        private int $retryCount,
        private ?string $lastError,
        private ?\DateTimeImmutable $nextRetryAt,
        private int $sequenceNumber,
    ) {
    }

    /**
     * Create a new outbox entry for a domain event.
     */
    public static function createForEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        string $eventPayload,
        string $topic,
        string $routingKey,
        int $sequenceNumber = self::DEFAULT_SEQUENCE,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            messageType: OutboxMessageType::EVENT,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            eventPayload: $eventPayload,
            topic: $topic,
            routingKey: $routingKey,
            createdAt: new \DateTimeImmutable(),
            publishedAt: null,
            retryCount: 0,
            lastError: null,
            nextRetryAt: null,
            sequenceNumber: $sequenceNumber,
        );
    }

    /**
     * Create a new outbox entry for a task command.
     */
    public static function createForTask(
        string $aggregateType,
        string $aggregateId,
        string $commandType,
        string $commandPayload,
        string $topic,
        string $routingKey,
        int $sequenceNumber = self::DEFAULT_SEQUENCE,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            messageType: OutboxMessageType::TASK,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $commandType,
            eventPayload: $commandPayload,
            topic: $topic,
            routingKey: $routingKey,
            createdAt: new \DateTimeImmutable(),
            publishedAt: null,
            retryCount: 0,
            lastError: null,
            nextRetryAt: null,
            sequenceNumber: $sequenceNumber,
        );
    }

    /**
     * Reconstruct an outbox entry from database row.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            messageType: OutboxMessageType::from($data['message_type']),
            aggregateType: $data['aggregate_type'],
            aggregateId: $data['aggregate_id'],
            eventType: $data['event_type'],
            eventPayload: $data['event_payload'],
            topic: $data['topic'],
            routingKey: $data['routing_key'],
            createdAt: new \DateTimeImmutable($data['created_at']),
            publishedAt: isset($data['published_at']) ? new \DateTimeImmutable($data['published_at']) : null,
            retryCount: (int) $data['retry_count'],
            lastError: $data['last_error'] ?? null,
            nextRetryAt: isset($data['next_retry_at']) ? new \DateTimeImmutable($data['next_retry_at']) : null,
            sequenceNumber: (int) $data['sequence_number'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMessageType(): OutboxMessageType
    {
        return $this->messageType;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEventPayload(): string
    {
        return $this->eventPayload;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getNextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof \DateTimeImmutable;
    }

    public function isEligibleForRetry(): bool
    {
        if ($this->isPublished()) {
            return false;
        }

        if ($this->retryCount >= self::MAX_RETRY_COUNT) {
            return false;
        }

        if (! $this->nextRetryAt instanceof \DateTimeImmutable) {
            return true;
        }

        return $this->nextRetryAt <= new \DateTimeImmutable();
    }

    public function markAsPublished(\DateTimeImmutable $publishedAt): OutboxEntryInterface
    {
        return new self(
            id: $this->id,
            messageType: $this->messageType,
            aggregateType: $this->aggregateType,
            aggregateId: $this->aggregateId,
            eventType: $this->eventType,
            eventPayload: $this->eventPayload,
            topic: $this->topic,
            routingKey: $this->routingKey,
            createdAt: $this->createdAt,
            publishedAt: $publishedAt,
            retryCount: $this->retryCount,
            lastError: null,
            nextRetryAt: null,
            sequenceNumber: $this->sequenceNumber,
        );
    }

    public function markAsFailed(string $error, \DateTimeImmutable $nextRetryAt): OutboxEntryInterface
    {
        return new self(
            id: $this->id,
            messageType: $this->messageType,
            aggregateType: $this->aggregateType,
            aggregateId: $this->aggregateId,
            eventType: $this->eventType,
            eventPayload: $this->eventPayload,
            topic: $this->topic,
            routingKey: $this->routingKey,
            createdAt: $this->createdAt,
            publishedAt: null,
            retryCount: $this->retryCount + 1,
            lastError: $error,
            nextRetryAt: $nextRetryAt,
            sequenceNumber: $this->sequenceNumber,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message_type' => $this->messageType->value,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'event_type' => $this->eventType,
            'event_payload' => $this->eventPayload,
            'topic' => $this->topic,
            'routing_key' => $this->routingKey,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s.u'),
            'retry_count' => $this->retryCount,
            'last_error' => $this->lastError,
            'next_retry_at' => $this->nextRetryAt?->format('Y-m-d H:i:s.u'),
            'sequence_number' => $this->sequenceNumber,
        ];
    }

    /**
     * Calculate exponential backoff delay for next retry.
     *
     * Formula: min(base_delay * 2^retry_count, max_delay)
     * Base delay: 1 second, Max delay: 5 minutes
     */
    public static function calculateNextRetryDelay(int $retryCount): int
    {
        $baseDelaySeconds = 1;
        $maxDelaySeconds = 300; // 5 minutes

        $delay = $baseDelaySeconds * (2 ** $retryCount);

        return min($delay, $maxDelaySeconds);
    }
}
