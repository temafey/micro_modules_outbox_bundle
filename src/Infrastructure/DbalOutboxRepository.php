<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Domain\OutboxPersistenceException;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;

/**
 * DBAL implementation of the outbox repository.
 *
 * Provides atomic persistence operations for outbox entries using
 * Doctrine DBAL. Designed to participate in the same database
 * transaction as aggregate saves for reliability.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.1-database-core-components.md
 */
final readonly class DbalOutboxRepository implements OutboxRepositoryInterface
{
    private const string TABLE_NAME = 'outbox';

    private const string SEQUENCE_NAME = 'outbox_sequence_seq';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function save(OutboxEntryInterface $entry): void
    {
        try {
            $data = $entry->toArray();
            $data['sequence_number'] = $this->getNextSequenceNumber();

            $this->connection->insert(self::TABLE_NAME, $data);
        } catch (DBALException $dbalException) {
            throw OutboxPersistenceException::saveFailed($entry->getId(), $dbalException);
        }
    }

    public function saveAll(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        try {
            foreach ($entries as $entry) {
                $data = $entry->toArray();
                $data['sequence_number'] = $this->getNextSequenceNumber();

                $this->connection->insert(self::TABLE_NAME, $data);
            }
        } catch (DBALException $dbalException) {
            throw OutboxPersistenceException::batchSaveFailed(count($entries), $dbalException);
        }
    }

    public function findUnpublished(int $limit): array
    {
        $sql = sprintf(
            'SELECT * FROM %s
             WHERE published_at IS NULL
               AND dead_letter_at IS NULL
               AND (next_retry_at IS NULL OR next_retry_at <= :now)
             ORDER BY sequence_number ASC
             LIMIT :limit',
            self::TABLE_NAME
        );

        $result = $this->connection->executeQuery($sql, [
            'now' => new \DateTimeImmutable()
                ->format('Y-m-d H:i:s.u'),
            'limit' => $limit,
        ]);

        return array_map(OutboxEntry::fromArray(...), $result->fetchAllAssociative());
    }

    public function findUnpublishedByType(OutboxMessageType $type, int $limit): array
    {
        $sql = sprintf(
            'SELECT * FROM %s
             WHERE published_at IS NULL
               AND dead_letter_at IS NULL
               AND message_type = :type
               AND (next_retry_at IS NULL OR next_retry_at <= :now)
             ORDER BY sequence_number ASC
             LIMIT :limit',
            self::TABLE_NAME
        );

        $result = $this->connection->executeQuery($sql, [
            'type' => $type->value,
            'now' => new \DateTimeImmutable()
                ->format('Y-m-d H:i:s.u'),
            'limit' => $limit,
        ]);

        return array_map(OutboxEntry::fromArray(...), $result->fetchAllAssociative());
    }

    public function markAsPublished(array $ids, \DateTimeImmutable $publishedAt): int
    {
        if ($ids === []) {
            return 0;
        }

        $paramSlots = implode(',', array_fill(0, count($ids), '?'));
        $sql = sprintf(
            'UPDATE %s
             SET published_at = ?, last_error = NULL, next_retry_at = NULL
             WHERE id IN (%s) AND published_at IS NULL',
            self::TABLE_NAME,
            $paramSlots
        );

        $params = array_merge([$publishedAt->format('Y-m-d H:i:s.u')], $ids);

        return $this->connection->executeStatement($sql, $params);
    }

    public function markAsFailed(string $id, string $error, \DateTimeImmutable $nextRetryAt): void
    {
        try {
            $affectedRows = $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s
                     SET retry_count = retry_count + 1,
                         last_error = :error,
                         next_retry_at = :next_retry_at
                     WHERE id = :id AND published_at IS NULL',
                    self::TABLE_NAME
                ),
                [
                    'id' => $id,
                    'error' => $this->truncateError($error),
                    'next_retry_at' => $nextRetryAt->format('Y-m-d H:i:s.u'),
                ]
            );

            if ($affectedRows === 0) {
                throw OutboxPersistenceException::notFound($id);
            }
        } catch (DBALException $dbalException) {
            throw OutboxPersistenceException::updateFailed($id, $dbalException);
        }
    }

    public function deletePublishedOlderThan(\DateTimeImmutable $olderThan): int
    {
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE published_at IS NOT NULL AND published_at < :older_than',
                self::TABLE_NAME
            ),
            [
                'older_than' => $olderThan->format('Y-m-d H:i:s.u'),
            ]
        );
    }

    public function countPublishedBefore(\DateTimeImmutable $before): int
    {
        $result = $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE published_at IS NOT NULL AND published_at < :before',
                self::TABLE_NAME
            ),
            [
                'before' => $before->format('Y-m-d H:i:s.u'),
            ]
        );

        return (int) $result->fetchOne();
    }

    public function deletePublishedBefore(\DateTimeImmutable $before, int $limit): int
    {
        // Use a subquery to limit the deletion for batching
        // PostgreSQL-specific: DELETE ... WHERE id IN (SELECT id ... LIMIT)
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s
                 WHERE id IN (
                     SELECT id FROM %s
                     WHERE published_at IS NOT NULL AND published_at < :before
                     ORDER BY published_at ASC
                     LIMIT :limit
                 )',
                self::TABLE_NAME,
                self::TABLE_NAME
            ),
            [
                'before' => $before->format('Y-m-d H:i:s.u'),
                'limit' => $limit,
            ]
        );
    }

    public function countFailedExceedingRetries(int $maxRetries): int
    {
        $result = $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*) FROM %s
                 WHERE published_at IS NULL AND retry_count >= :max_retries',
                self::TABLE_NAME
            ),
            [
                'max_retries' => $maxRetries,
            ]
        );

        return (int) $result->fetchOne();
    }

    public function deleteFailedExceedingRetries(int $maxRetries, int $limit): int
    {
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s
                 WHERE id IN (
                     SELECT id FROM %s
                     WHERE published_at IS NULL AND retry_count >= :max_retries
                     ORDER BY created_at ASC
                     LIMIT :limit
                 )',
                self::TABLE_NAME,
                self::TABLE_NAME
            ),
            [
                'max_retries' => $maxRetries,
                'limit' => $limit,
            ]
        );
    }

    public function markAsDeadLetter(string $id, \DateTimeImmutable $deadLetterAt): void
    {
        try {
            $affectedRows = $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s
                     SET dead_letter_at = :dead_letter_at
                     WHERE id = :id AND published_at IS NULL',
                    self::TABLE_NAME
                ),
                [
                    'id' => $id,
                    'dead_letter_at' => $deadLetterAt->format('Y-m-d H:i:s.u'),
                ]
            );

            if ($affectedRows === 0) {
                throw OutboxPersistenceException::notFound($id);
            }
        } catch (DBALException $dbalException) {
            throw OutboxPersistenceException::updateFailed($id, $dbalException);
        }
    }

    public function findDeadLetter(int $limit): array
    {
        $sql = sprintf(
            'SELECT * FROM %s
             WHERE dead_letter_at IS NOT NULL
             ORDER BY dead_letter_at ASC
             LIMIT :limit',
            self::TABLE_NAME
        );

        $result = $this->connection->executeQuery($sql, [
            'limit' => $limit,
        ]);

        return array_map(OutboxEntry::fromArray(...), $result->fetchAllAssociative());
    }

    public function countDeadLetter(): int
    {
        $result = $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE dead_letter_at IS NOT NULL',
                self::TABLE_NAME
            )
        );

        return (int) $result->fetchOne();
    }

    public function replayDeadLetter(string $id): bool
    {
        try {
            $affectedRows = $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s
                     SET dead_letter_at = NULL,
                         retry_count = 0,
                         last_error = NULL,
                         next_retry_at = NULL
                     WHERE id = :id AND dead_letter_at IS NOT NULL AND published_at IS NULL',
                    self::TABLE_NAME
                ),
                [
                    'id' => $id,
                ]
            );

            return $affectedRows > 0;
        } catch (DBALException $dbalException) {
            throw OutboxPersistenceException::updateFailed($id, $dbalException);
        }
    }

    public function countDeadLetterBefore(\DateTimeImmutable $before): int
    {
        $result = $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*) FROM %s
                 WHERE dead_letter_at IS NOT NULL AND dead_letter_at < :before',
                self::TABLE_NAME
            ),
            [
                'before' => $before->format('Y-m-d H:i:s.u'),
            ]
        );

        return (int) $result->fetchOne();
    }

    public function deleteDeadLetterBefore(\DateTimeImmutable $before, int $limit): int
    {
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s
                 WHERE id IN (
                     SELECT id FROM %s
                     WHERE dead_letter_at IS NOT NULL AND dead_letter_at < :before
                     ORDER BY dead_letter_at ASC
                     LIMIT :limit
                 )',
                self::TABLE_NAME,
                self::TABLE_NAME
            ),
            [
                'before' => $before->format('Y-m-d H:i:s.u'),
                'limit' => $limit,
            ]
        );
    }

    public function findById(string $id): ?OutboxEntryInterface
    {
        $result = $this->connection->executeQuery(
            sprintf('SELECT * FROM %s WHERE id = :id', self::TABLE_NAME),
            [
                'id' => $id,
            ]
        );

        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return OutboxEntry::fromArray($row);
    }

    public function getMetrics(): array
    {
        $sql = sprintf(
            'SELECT
                COUNT(*) FILTER (WHERE published_at IS NULL AND dead_letter_at IS NULL) as total_pending,
                COUNT(*) FILTER (WHERE published_at IS NULL AND dead_letter_at IS NULL AND message_type = \'EVENT\') as total_events,
                COUNT(*) FILTER (WHERE published_at IS NULL AND dead_letter_at IS NULL AND message_type = \'TASK\') as total_tasks,
                COUNT(*) FILTER (WHERE retry_count > 0 AND published_at IS NULL AND dead_letter_at IS NULL) as failed_count,
                COUNT(*) FILTER (WHERE dead_letter_at IS NOT NULL) as dlq_count,
                EXTRACT(EPOCH FROM (NOW() - MIN(created_at) FILTER (WHERE published_at IS NULL AND dead_letter_at IS NULL)))::integer as oldest_pending_seconds
             FROM %s',
            self::TABLE_NAME
        );

        $result = $this->connection->executeQuery($sql)
            ->fetchAssociative();

        return [
            'total_pending' => (int) ($result['total_pending'] ?? 0),
            'total_events' => (int) ($result['total_events'] ?? 0),
            'total_tasks' => (int) ($result['total_tasks'] ?? 0),
            'failed_count' => (int) ($result['failed_count'] ?? 0),
            'dlq_count' => (int) ($result['dlq_count'] ?? 0),
            'oldest_pending_seconds' => $result['oldest_pending_seconds'] !== null
                ? (int) $result['oldest_pending_seconds']
                : null,
        ];
    }

    public function countByStatus(): array
    {
        $sql = sprintf(
            'SELECT
                COUNT(*) FILTER (WHERE published_at IS NULL AND retry_count = 0) as pending,
                COUNT(*) FILTER (WHERE published_at IS NOT NULL) as published,
                COUNT(*) FILTER (WHERE retry_count > 0 AND published_at IS NULL) as failed
             FROM %s',
            self::TABLE_NAME
        );

        $result = $this->connection->executeQuery($sql)
            ->fetchAssociative();

        return [
            'pending' => (int) ($result['pending'] ?? 0),
            'published' => (int) ($result['published'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ];
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollBack();
    }

    public function isTransactionActive(): bool
    {
        return $this->connection->isTransactionActive();
    }

    /**
     * Get the DBAL connection for transaction sharing.
     *
     * Used by decorators (OutboxAwareEventStore, OutboxAwareTaskRepository)
     * to ensure they share the same transaction.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the next sequence number from the database sequence.
     */
    private function getNextSequenceNumber(): int
    {
        $result = $this->connection->executeQuery(sprintf("SELECT nextval('%s')", self::SEQUENCE_NAME));

        return (int) $result->fetchOne();
    }

    /**
     * Truncate error message to prevent database overflow.
     *
     * Limits error message to 4000 characters for storage.
     */
    private function truncateError(string $error): string
    {
        $maxLength = 4000;

        if (strlen($error) <= $maxLength) {
            return $error;
        }

        return substr($error, 0, $maxLength - 3) . '...';
    }
}
