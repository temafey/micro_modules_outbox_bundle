<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Publisher;

use MicroModule\Outbox\Domain\OutboxEntryInterface;
use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Infrastructure\Observability\TracerFactoryInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unified outbox publisher that routes messages to appropriate handlers.
 *
 * Delegates publishing based on message_type:
 * - 'event' → EventPublisher (QueueEventInterface)
 * - 'task' → TaskPublisher (ProducerInterface)
 *
 * Includes OpenTelemetry tracing for full distributed trace visibility
 * across the outbox publish pipeline.
 *
 * @see ADR-006: Transactional Outbox Pattern
 * @see TASK-14.4: Background Publisher
 */
final readonly class OutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private OutboxPublisherInterface $eventPublisher,
        private OutboxPublisherInterface $taskPublisher,
        private TracerFactoryInterface $tracerFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function publish(OutboxEntryInterface $entry): void
    {
        $tracer = $this->tracerFactory->getTracer();
        $messageType = $entry->getMessageType();

        $span = $tracer->spanBuilder('outbox.publish')
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('messaging.system', 'outbox')
            ->setAttribute('messaging.operation', 'publish')
            ->setAttribute('messaging.destination.name', $entry->getTopic())
            ->setAttribute('outbox.message_id', $entry->getId())
            ->setAttribute('outbox.message_type', $messageType->value)
            ->setAttribute('outbox.event_type', $entry->getEventType())
            ->setAttribute('outbox.aggregate_type', $entry->getAggregateType())
            ->setAttribute('outbox.aggregate_id', $entry->getAggregateId())
            ->setAttribute('outbox.retry_count', $entry->getRetryCount())
            ->setAttribute('outbox.routing_key', $entry->getRoutingKey())
            ->startSpan();

        $scope = $span->activate();

        try {
            $publisher = $this->resolvePublisher($messageType);
            $publisher->publish($entry);

            $span->setStatus(StatusCode::STATUS_OK);
            $span->setAttribute('outbox.result', 'published');

            // Record trace ID for correlation
            $spanContext = $span->getContext();
            if ($spanContext->isValid()) {
                $span->setAttribute('outbox.trace_id', $spanContext->getTraceId());
            }

            $this->logger->info('Outbox message published', [
                'message_id' => $entry->getId(),
                'message_type' => $messageType->value,
                'event_type' => $entry->getEventType(),
                'topic' => $entry->getTopic(),
                'routing_key' => $entry->getRoutingKey(),
            ]);
        } catch (\Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
            $span->setAttribute('outbox.result', 'failed');
            $span->setAttribute('outbox.error', $throwable->getMessage());

            $this->logger->error('Outbox message publish failed', [
                'message_id' => $entry->getId(),
                'message_type' => $messageType->value,
                'event_type' => $entry->getEventType(),
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function supports(string $messageType): bool
    {
        return in_array($messageType, [OutboxMessageType::EVENT->value, OutboxMessageType::TASK->value], true);
    }

    /**
     * Resolve the appropriate publisher for the message type.
     */
    private function resolvePublisher(OutboxMessageType $messageType): OutboxPublisherInterface
    {
        return match ($messageType) {
            OutboxMessageType::EVENT => $this->eventPublisher,
            OutboxMessageType::TASK => $this->taskPublisher,
        };
    }
}
