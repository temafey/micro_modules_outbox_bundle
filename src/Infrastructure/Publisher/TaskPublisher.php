<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Publisher;

use Broadway\CommandHandling\CommandBus;
use MicroModule\Outbox\Application\Task\CommandFactory;
use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Publisher for task commands stored in the outbox.
 *
 * Reads a TASK outbox entry, deserializes the command payload via
 * CommandFactory (Broadway Serializable::deserialize() format), then
 * dispatches the resulting command object through the CommandBus.
 *
 * JSON / deserialization errors are caught and re-thrown as
 * OutboxPublishException::deserializationFailed so that the outbox
 * poller can record the failure and apply retry / dead-letter logic
 * without crashing the publish loop.
 *
 * @see \MicroModule\Outbox\Application\Task\CommandFactory
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-01-10: VP-7b — CommandFactory for TaskPublisher
 */
final class TaskPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly CommandFactory $commandFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function publish(OutboxEntryInterface $entry): void
    {
        $commandClass = $entry->getEventType();
        $payloadJson = $entry->getEventPayload();

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OutboxPublishException::deserializationFailed($commandClass, $e->getMessage());
        }

        try {
            $command = $this->commandFactory->deserialize($commandClass, $payload);
        } catch (\Throwable $e) {
            throw OutboxPublishException::deserializationFailed($commandClass, $e->getMessage());
        }

        $this->commandBus->dispatch($command);

        $this->logger->debug('Task command published from outbox', [
            'message_id' => $entry->getId(),
            'command_class' => $commandClass,
            'routing_key' => $entry->getRoutingKey(),
        ]);
    }

    public function supports(string $messageType): bool
    {
        return $messageType === OutboxMessageType::TASK->value;
    }
}
