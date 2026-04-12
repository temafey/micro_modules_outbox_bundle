<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use Enqueue\Rpc\Promise;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Saga\Application\SagaCommandQueueInterface;
use MicroModule\Task\Application\Processor\JobCommandBusProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

/**
 * Outbox-aware decorator for Enqueue task producer.
 *
 * Intercepts sendCommand() calls and writes to the outbox table instead
 * of sending directly to RabbitMQ. The background OutboxPublisher will
 * later read from outbox and perform actual message delivery.
 *
 * This ensures atomic writes: the task command is stored in the same
 * database transaction as the business operation, preventing dual-write
 * problems and ensuring reliable message delivery.
 *
 * IMPORTANT: Events are NOT intercepted here - they flow through to the
 * inner producer. Domain events use OutboxAwareEventStore instead.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.3-taskrepository-decorator.md
 * @see OutboxPublisherCommand for the async publishing worker
 */
final class OutboxAwareTaskProducer implements OutboxAwareProducerInterface, SagaCommandQueueInterface
{
    public function __construct(
        private readonly ProducerInterface $inner,
        private readonly OutboxRepositoryInterface $outboxRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
        private bool $enabled = true,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Intercepts command sending and writes to outbox table atomically.
     * The command will be published by the background OutboxPublisher.
     *
     * @param string $command   The command route (e.g., 'job_command_bus')
     * @param mixed  $message   Message payload (array or Message object)
     * @param bool   $needReply Whether a reply is expected (not supported with outbox)
     */
    public function sendCommand(string $command, $message, bool $needReply = false): ?Promise
    {
        if (! $this->enabled) {
            $this->logger->debug('Outbox disabled, sending command directly', [
                'command' => $command,
            ]);

            return $this->inner->sendCommand($command, $message, $needReply);
        }

        // Reply-based commands are not supported with outbox pattern
        if ($needReply) {
            throw new \InvalidArgumentException(
                'Reply-based commands are not supported with outbox pattern. Use async messaging with correlation IDs instead.',
            );
        }

        $this->writeToOutbox($command, $message);

        $this->logger->debug('Task command written to outbox', [
            'command' => $command,
        ]);

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * Implements SagaCommandQueueInterface::enqueue() by writing a TASK outbox entry.
     *
     * Accepts a fully-qualified command class name and its Broadway-serializable
     * payload array. The payload is JSON-encoded and stored atomically in the
     * outbox table within the current database transaction.
     *
     * The routing key is determined from JobCommandBusProcessor::getRoute() so
     * the TaskPublisher can dispatch the command to the correct queue.
     *
     * @param class-string         $commandClass Fully-qualified command class name
     * @param array<string, mixed> $payload      Broadway Serializable::serialize() format
     */
    public function enqueue(string $commandClass, array $payload): void
    {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $aggregateType = $this->extractAggregateType($commandClass);
        $aggregateId = $this->extractAggregateId($payload);
        $routingKey = $this->determineRoutingKey();

        $entry = OutboxEntry::createForTask(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            commandType: $commandClass,
            commandPayload: $payloadJson,
            topic: $routingKey,
            routingKey: $routingKey,
        );

        $this->outboxRepository->save($entry);

        $this->logger->debug('Saga command enqueued to outbox', [
            'command_class' => $commandClass,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'entry_id' => $entry->getId(),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Event sending passes through to inner producer.
     * Domain events use OutboxAwareEventStore, not this producer.
     */
    public function sendEvent(string $topic, mixed $message): void
    {
        // Events are handled by OutboxAwareEventStore, not here
        $this->inner->sendEvent($topic, $message);
    }

    public function isOutboxEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
        $this->logger->info('Outbox task producer enabled');
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->logger->info('Outbox task producer disabled');
    }

    /**
     * Write task command to outbox table.
     *
     * @param string $command The command route
     * @param mixed  $message The message payload
     */
    private function writeToOutbox(string $command, mixed $message): void
    {
        $payload = $this->normalizePayload($message);

        // Extract type and args from payload (TaskRepository message format)
        $commandType = $payload['type'] ?? $command;
        $commandArgs = $payload['args'] ?? $payload;

        // Determine aggregate context from args (if available)
        $aggregateId = $this->extractAggregateId($commandArgs);
        $aggregateType = $this->extractAggregateType($commandType);

        // Determine routing key from JobCommandBusProcessor
        $routingKey = $this->determineRoutingKey();

        // JSON encode payload for storage
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $entry = OutboxEntry::createForTask(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            commandType: $commandType,
            commandPayload: $payloadJson,
            topic: $command,
            routingKey: $routingKey,
        );

        $this->outboxRepository->save($entry);

        $this->logger->debug('Outbox entry created for task command', [
            'entry_id' => $entry->getId(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'command_type' => $commandType,
            'topic' => $command,
        ]);
    }

    /**
     * Normalize message to array payload.
     *
     * @param mixed $message The original message (array, Message object, string, etc.)
     *
     * @return array<string, mixed> Normalized payload array
     */
    private function normalizePayload(mixed $message): array
    {
        if ($message instanceof Message) {
            $body = $message->getBody();
            // Message::getBody() returns string|null — JSON-decode if possible
            if (is_string($body)) {
                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : ['data' => $body];
            }

            return ['data' => $body];
        }

        if (is_array($message)) {
            return $message;
        }

        if (is_string($message)) {
            $decoded = json_decode($message, true);

            return is_array($decoded) ? $decoded : [
                'data' => $message,
            ];
        }

        return [
            'data' => $message,
        ];
    }

    /**
     * Extract aggregate ID from command arguments.
     *
     * Task commands typically have structure:
     * - Create: [processUuid, data]
     * - Update/Action: [processUuid, entityUuid, data?]
     *
     * We prefer the entity UUID (second element) as aggregate ID.
     *
     * @param array<int|string, mixed> $args Command arguments
     */
    private function extractAggregateId(array $args): string
    {
        // Ensure we're working with numeric indexed array
        $indexedArgs = array_values($args);

        // Try to find UUID in args (usually second element is entity UUID)
        if (isset($indexedArgs[1]) && $this->isValidUuid($indexedArgs[1])) {
            return (string) $indexedArgs[1];
        }

        // Fall back to process UUID (first element)
        if (isset($indexedArgs[0]) && $this->isValidUuid($indexedArgs[0])) {
            return (string) $indexedArgs[0];
        }

        // Generate new UUID if none found
        return Uuid::uuid4()->toString();
    }

    /**
     * Extract aggregate type from command type.
     *
     * Example: 'news.create.command' -> 'News'
     * Example: 'identity.user.create.command' -> 'Identity'
     *
     * @param string $commandType The command type string
     */
    private function extractAggregateType(string $commandType): string
    {
        $parts = explode('.', $commandType);

        if ($parts[0] !== '') {
            return ucfirst($parts[0]); // 'news' -> 'News'
        }

        return 'Unknown';
    }

    /**
     * Determine the routing key for task commands.
     *
     * Uses JobCommandBusProcessor::getRoute() for consistent routing.
     */
    private function determineRoutingKey(): string
    {
        // JobCommandBusProcessor defines the route for task commands
        if (class_exists(JobCommandBusProcessor::class)) {
            return JobCommandBusProcessor::getRoute();
        }

        // Fallback routing key
        return 'job_command_bus';
    }

    /**
     * Validate if value is a valid UUID string.
     */
    private function isValidUuid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // UUID v4 pattern: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
