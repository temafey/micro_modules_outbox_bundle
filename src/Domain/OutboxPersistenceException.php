<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Domain;

/**
 * Exception thrown when outbox persistence operations fail.
 *
 * This exception indicates a critical failure in the outbox pattern
 * that should typically trigger a transaction rollback.
 */
final class OutboxPersistenceException extends \RuntimeException
{
    private function __construct(
        string $message,
        private readonly ?string $entryId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create exception for save failure.
     */
    public static function saveFailed(string $entryId, \Throwable $previous): self
    {
        return new self(
            message: sprintf('Failed to save outbox entry %s: %s', $entryId, $previous->getMessage()),
            entryId: $entryId,
            previous: $previous,
        );
    }

    /**
     * Create exception for batch save failure.
     */
    public static function batchSaveFailed(int $count, \Throwable $previous): self
    {
        return new self(
            message: sprintf('Failed to save %d outbox entries: %s', $count, $previous->getMessage()),
            previous: $previous,
        );
    }

    /**
     * Create exception for update failure.
     */
    public static function updateFailed(string $entryId, \Throwable $previous): self
    {
        return new self(
            message: sprintf('Failed to update outbox entry %s: %s', $entryId, $previous->getMessage()),
            entryId: $entryId,
            previous: $previous,
        );
    }

    /**
     * Create exception for entry not found.
     */
    public static function notFound(string $entryId): self
    {
        return new self(message: sprintf('Outbox entry not found: %s', $entryId), entryId: $entryId);
    }

    /**
     * Get the entry ID associated with this exception.
     */
    public function getEntryId(): ?string
    {
        return $this->entryId;
    }
}
