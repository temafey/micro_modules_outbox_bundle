<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Domain;

/**
 * Interface for outbox entry value objects.
 *
 * Represents a single message stored in the transactional outbox table.
 * Messages can be either domain events or task commands.
 *
 * The outbox pattern ensures reliable message delivery by atomically
 * writing messages to the database within the same transaction as
 * the business operation, then publishing asynchronously.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.1-database-core-components.md
 */
interface OutboxEntryInterface
{
    /**
     * Get the unique identifier for this outbox entry.
     */
    public function getId(): string;

    /**
     * Get the message type discriminator (EVENT or TASK).
     */
    public function getMessageType(): OutboxMessageType;

    /**
     * Get the aggregate type (e.g., 'News', 'User').
     */
    public function getAggregateType(): string;

    /**
     * Get the aggregate ID (UUID of the entity).
     */
    public function getAggregateId(): string;

    /**
     * Get the event or command type name.
     */
    public function getEventType(): string;

    /**
     * Get the serialized event/command payload as JSON string.
     */
    public function getEventPayload(): string;

    /**
     * Get the target topic/exchange for publishing.
     */
    public function getTopic(): string;

    /**
     * Get the routing key for message routing.
     */
    public function getRoutingKey(): string;

    /**
     * Get the timestamp when this entry was created.
     */
    public function getCreatedAt(): \DateTimeImmutable;

    /**
     * Get the timestamp when this entry was published (null if unpublished).
     */
    public function getPublishedAt(): ?\DateTimeImmutable;

    /**
     * Get the number of publish retry attempts.
     */
    public function getRetryCount(): int;

    /**
     * Get the last error message from failed publish attempt.
     */
    public function getLastError(): ?string;

    /**
     * Get the next retry timestamp for exponential backoff.
     */
    public function getNextRetryAt(): ?\DateTimeImmutable;

    /**
     * Get the sequence number for ordered processing.
     */
    public function getSequenceNumber(): int;

    /**
     * Check if this entry has been published.
     */
    public function isPublished(): bool;

    /**
     * Check if this entry is eligible for retry.
     */
    public function isEligibleForRetry(): bool;

    /**
     * Create a new instance marked as published.
     */
    public function markAsPublished(\DateTimeImmutable $publishedAt): self;

    /**
     * Create a new instance with incremented retry count and error.
     */
    public function markAsFailed(string $error, \DateTimeImmutable $nextRetryAt): self;

    /**
     * Convert to array for database storage.
     */
    public function toArray(): array;
}
