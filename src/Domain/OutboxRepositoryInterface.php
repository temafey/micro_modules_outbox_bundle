<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Domain;

/**
 * Repository interface for outbox entry persistence.
 *
 * Provides atomic operations for storing and retrieving outbox messages
 * as part of the transactional outbox pattern.
 *
 * IMPORTANT: The save() method MUST participate in the same database
 * transaction as the business operation that generated the message.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.1-database-core-components.md
 */
interface OutboxRepositoryInterface
{
    /**
     * Save a single outbox entry.
     *
     * MUST be called within the same transaction as the aggregate save.
     */
    public function save(OutboxEntryInterface $entry): void;

    /**
     * Save multiple outbox entries atomically.
     *
     * MUST be called within the same transaction as the aggregate save.
     *
     * @param OutboxEntryInterface[] $entries
     */
    public function saveAll(array $entries): void;

    /**
     * Find unpublished entries eligible for publishing.
     *
     * Returns entries ordered by sequence_number to maintain ordering.
     * Only returns entries where:
     * - published_at IS NULL
     * - next_retry_at IS NULL OR next_retry_at <= NOW()
     *
     * @param int $limit Maximum entries to return
     *
     * @return OutboxEntryInterface[]
     */
    public function findUnpublished(int $limit): array;

    /**
     * Find unpublished entries by message type.
     *
     * @param int $limit Maximum entries to return
     *
     * @return OutboxEntryInterface[]
     */
    public function findUnpublishedByType(OutboxMessageType $type, int $limit): array;

    /**
     * Mark entries as published.
     *
     * @param string[]           $ids         Entry IDs to mark as published
     * @param \DateTimeImmutable $publishedAt Timestamp of publication
     *
     * @return int Number of entries updated
     */
    public function markAsPublished(array $ids, \DateTimeImmutable $publishedAt): int;

    /**
     * Mark a single entry as failed with error and next retry time.
     *
     * Increments the retry_count and sets last_error and next_retry_at.
     */
    public function markAsFailed(string $id, string $error, \DateTimeImmutable $nextRetryAt): void;

    /**
     * Delete published entries older than the given timestamp.
     *
     * Used for cleanup of old outbox entries to prevent table bloat.
     *
     * @return int Number of entries deleted
     */
    public function deletePublishedOlderThan(\DateTimeImmutable $olderThan): int;

    /**
     * Count published entries older than the given timestamp.
     *
     * Used for dry-run cleanup preview.
     */
    public function countPublishedBefore(\DateTimeImmutable $before): int;

    /**
     * Delete published entries older than the given timestamp in batches.
     *
     * Returns the number actually deleted (may be less than limit).
     *
     * @param int $limit Maximum entries to delete per batch
     *
     * @return int Number of entries deleted
     */
    public function deletePublishedBefore(\DateTimeImmutable $before, int $limit): int;

    /**
     * Count failed entries exceeding the maximum retry count.
     *
     * Used for dry-run cleanup preview of dead-letter messages.
     */
    public function countFailedExceedingRetries(int $maxRetries): int;

    /**
     * Delete failed entries exceeding the maximum retry count in batches.
     *
     * Removes dead-letter messages that will never be successfully published.
     *
     * @param int $maxRetries Maximum retries threshold
     * @param int $limit      Maximum entries to delete per batch
     *
     * @return int Number of entries deleted
     */
    public function deleteFailedExceedingRetries(int $maxRetries, int $limit): int;

    /**
     * Get an entry by ID.
     */
    public function findById(string $id): ?OutboxEntryInterface;

    /**
     * Get metrics about the outbox queue.
     *
     * @return array{
     *     total_pending: int,
     *     total_events: int,
     *     total_tasks: int,
     *     failed_count: int,
     *     oldest_pending_seconds: int|null
     * }
     */
    public function getMetrics(): array;

    /**
     * Count entries by status and type.
     *
     * @return array{
     *     pending: int,
     *     published: int,
     *     failed: int
     * }
     */
    public function countByStatus(): array;

    /**
     * Begin a database transaction.
     *
     * Use when the outbox write needs to start a new transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the current database transaction.
     */
    public function commit(): void;

    /**
     * Rollback the current database transaction.
     */
    public function rollback(): void;

    /**
     * Check if currently in a transaction.
     */
    public function isTransactionActive(): bool;
}
