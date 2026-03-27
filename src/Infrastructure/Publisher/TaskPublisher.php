<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Publisher;

use Enqueue\Client\ProducerInterface;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Task\Application\Processor\JobCommandBusProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Publisher for task commands stored in the outbox.
 *
 * Sends task commands through the Enqueue ProducerInterface using
 * the same message format as direct TaskRepository.produce() calls.
 *
 * Message format:
 * {
 *   "type": "news.create.command",
 *   "args": [processUuid, ...command args]
 * }
 *
 * IMPORTANT: This publisher uses the inner ProducerInterface directly,
 * NOT the OutboxAwareTaskProducer decorator, to avoid infinite loops.
 * The service configuration must inject 'enqueue.client.task.producer.inner'
 * or disable outbox mode when publishing.
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.3: TaskRepository Decorator
 * @see TASK-14.4: Background Publisher
 */
final readonly class TaskPublisher implements OutboxPublisherInterface
{
    private const string KEY_MESSAGE_TYPE = 'type';

    private const string KEY_MESSAGE_ARGS = 'args';

    public function __construct(
        private ProducerInterface $taskProducer,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function publish(OutboxEntryInterface $entry): void
    {
        $payloadJson = $entry->getEventPayload();

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw OutboxPublishException::invalidTaskFormat(
                $entry->getId(),
                'Invalid JSON payload: ' . $jsonException->getMessage(),
            );
        }

        // Validate payload structure
        if (! isset($payload[self::KEY_MESSAGE_TYPE])) {
            throw OutboxPublishException::invalidTaskFormat(
                $entry->getId(),
                sprintf('Missing required key: %s', self::KEY_MESSAGE_TYPE),
            );
        }

        if (! isset($payload[self::KEY_MESSAGE_ARGS]) || ! is_array($payload[self::KEY_MESSAGE_ARGS])) {
            throw OutboxPublishException::invalidTaskFormat(
                $entry->getId(),
                sprintf('Missing or invalid key: %s (expected array)', self::KEY_MESSAGE_ARGS),
            );
        }

        // Determine routing - use JobCommandBusProcessor route
        $route = $this->determineRoute();

        // Send to task queue using same route as direct produce()
        $this->taskProducer->sendCommand($route, $payload);

        $this->logger->debug('Task published from outbox', [
            'message_id' => $entry->getId(),
            'command_type' => $payload[self::KEY_MESSAGE_TYPE],
            'routing_key' => $entry->getRoutingKey(),
            'route' => $route,
        ]);
    }

    public function supports(string $messageType): bool
    {
        return $messageType === OutboxMessageType::TASK->value;
    }

    /**
     * Determine the routing key for task commands.
     *
     * Uses JobCommandBusProcessor::getRoute() for consistent routing.
     */
    private function determineRoute(): string
    {
        if (class_exists(JobCommandBusProcessor::class)) {
            return JobCommandBusProcessor::getRoute();
        }

        // Fallback routing key
        return 'job_command_bus';
    }
}
