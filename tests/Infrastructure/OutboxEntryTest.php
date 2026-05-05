<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure;

use MicroModule\Outbox\Domain\OutboxMessageType;
use MicroModule\Outbox\Infrastructure\OutboxEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxEntry::class)]
final class OutboxEntryTest extends TestCase
{
    #[Test]
    public function createForEventSetsCorrectDefaults(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{"title":"Test"}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        self::assertNotEmpty($entry->getId());
        self::assertSame(OutboxMessageType::EVENT, $entry->getMessageType());
        self::assertSame('News', $entry->getAggregateType());
        self::assertSame('agg-1', $entry->getAggregateId());
        self::assertSame('NewsCreatedEvent', $entry->getEventType());
        self::assertSame('{"title":"Test"}', $entry->getEventPayload());
        self::assertSame('events.news', $entry->getTopic());
        self::assertSame('event.news_created', $entry->getRoutingKey());
        self::assertNull($entry->getPublishedAt());
        self::assertSame(0, $entry->getRetryCount());
        self::assertNull($entry->getLastError());
        self::assertNull($entry->getNextRetryAt());
        self::assertFalse($entry->isPublished());
        self::assertTrue($entry->isEligibleForRetry());
    }

    #[Test]
    public function createForTaskSetsTaskMessageType(): void
    {
        $entry = OutboxEntry::createForTask(
            aggregateType: 'News',
            aggregateId: 'agg-2',
            commandType: 'CreateNewsCommand',
            commandPayload: '{"name":"Test"}',
            topic: 'commands.news',
            routingKey: 'command.create_news',
        );

        self::assertSame(OutboxMessageType::TASK, $entry->getMessageType());
        self::assertSame('CreateNewsCommand', $entry->getEventType());
    }

    #[Test]
    public function fromArrayReconstructsEntry(): void
    {
        $data = [
            'id' => 'test-id-123',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-1',
            'event_type' => 'NewsCreatedEvent',
            'event_payload' => '{"title":"Test"}',
            'topic' => 'events.news',
            'routing_key' => 'event.news_created',
            'created_at' => '2026-03-27 10:00:00.000000',
            'published_at' => null,
            'retry_count' => '0',
            'last_error' => null,
            'next_retry_at' => null,
            'sequence_number' => '42',
        ];

        $entry = OutboxEntry::fromArray($data);

        self::assertSame('test-id-123', $entry->getId());
        self::assertSame(OutboxMessageType::EVENT, $entry->getMessageType());
        self::assertSame('News', $entry->getAggregateType());
        self::assertSame(42, $entry->getSequenceNumber());
    }

    #[Test]
    public function fromArrayWithPublishedAt(): void
    {
        $data = [
            'id' => 'test-id-456',
            'message_type' => 'TASK',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-2',
            'event_type' => 'SomeCommand',
            'event_payload' => '{}',
            'topic' => 'commands.news',
            'routing_key' => 'cmd.some',
            'created_at' => '2026-03-27 10:00:00.000000',
            'published_at' => '2026-03-27 10:01:00.000000',
            'retry_count' => '0',
            'last_error' => null,
            'next_retry_at' => null,
            'sequence_number' => '1',
        ];

        $entry = OutboxEntry::fromArray($data);

        self::assertTrue($entry->isPublished());
        self::assertNotNull($entry->getPublishedAt());
    }

    #[Test]
    public function toArrayRoundTrips(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{"title":"Test"}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $array = $entry->toArray();

        self::assertSame($entry->getId(), $array['id']);
        self::assertSame('EVENT', $array['message_type']);
        self::assertSame('News', $array['aggregate_type']);
        self::assertSame('agg-1', $array['aggregate_id']);
        self::assertNull($array['published_at']);
        self::assertSame(0, $array['retry_count']);
    }

    #[Test]
    public function markAsPublishedReturnsNewInstance(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $publishedAt = new \DateTimeImmutable('2026-03-27 12:00:00');
        $published = $entry->markAsPublished($publishedAt);

        self::assertFalse($entry->isPublished());
        self::assertTrue($published->isPublished());
        self::assertSame($publishedAt, $published->getPublishedAt());
        self::assertSame($entry->getId(), $published->getId());
        self::assertNull($published->getLastError());
        self::assertNull($published->getNextRetryAt());
    }

    #[Test]
    public function markAsFailedIncrementsRetryCount(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'NewsCreatedEvent',
            eventPayload: '{}',
            topic: 'events.news',
            routingKey: 'event.news_created',
        );

        $nextRetry = new \DateTimeImmutable('+60 seconds');
        $failed = $entry->markAsFailed('Connection refused', $nextRetry);

        self::assertSame(0, $entry->getRetryCount());
        self::assertSame(1, $failed->getRetryCount());
        self::assertSame('Connection refused', $failed->getLastError());
        self::assertSame($nextRetry, $failed->getNextRetryAt());
        self::assertFalse($failed->isPublished());
    }

    #[Test]
    public function isEligibleForRetryFalseWhenPublished(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        $published = $entry->markAsPublished(new \DateTimeImmutable());

        self::assertFalse($published->isEligibleForRetry());
    }

    #[Test]
    public function isEligibleForRetryFalseWhenMaxRetriesExceeded(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        // Fail 10 times to reach MAX_RETRY_COUNT
        $failed = $entry;
        for ($i = 0; $i < 10; $i++) {
            $failed = $failed->markAsFailed('error', new \DateTimeImmutable('-1 hour'));
        }

        self::assertSame(10, $failed->getRetryCount());
        self::assertFalse($failed->isEligibleForRetry());
    }

    #[Test]
    public function isEligibleForRetryFalseWhenNextRetryInFuture(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        $failed = $entry->markAsFailed('error', new \DateTimeImmutable('+1 hour'));

        self::assertFalse($failed->isEligibleForRetry());
    }

    #[Test]
    public function calculateNextRetryDelayExponentialBackoff(): void
    {
        self::assertSame(1, OutboxEntry::calculateNextRetryDelay(0));
        self::assertSame(2, OutboxEntry::calculateNextRetryDelay(1));
        self::assertSame(4, OutboxEntry::calculateNextRetryDelay(2));
        self::assertSame(8, OutboxEntry::calculateNextRetryDelay(3));
        self::assertSame(16, OutboxEntry::calculateNextRetryDelay(4));
    }

    #[Test]
    public function calculateNextRetryDelayCapsAtFiveMinutes(): void
    {
        self::assertSame(300, OutboxEntry::calculateNextRetryDelay(10));
        self::assertSame(300, OutboxEntry::calculateNextRetryDelay(20));
    }

    #[Test]
    public function newEntryIsNotDeadLetter(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        self::assertNull($entry->getDeadLetterAt());
        self::assertFalse($entry->isDeadLetter());
    }

    #[Test]
    public function markAsDeadLetterStampsTimestampAndPreservesOtherFields(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );
        $stamp = new \DateTimeImmutable('2026-05-05 12:00:00');

        $dlq = $entry->markAsDeadLetter($stamp);

        self::assertTrue($dlq->isDeadLetter());
        self::assertSame($stamp, $dlq->getDeadLetterAt());
        self::assertSame($entry->getId(), $dlq->getId());
        self::assertSame($entry->getRetryCount(), $dlq->getRetryCount());
        // Original is unchanged (immutability)
        self::assertFalse($entry->isDeadLetter());
    }

    #[Test]
    public function deadLetterEntryIsNotEligibleForRetry(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        $dlq = $entry->markAsDeadLetter(new \DateTimeImmutable());

        self::assertFalse($dlq->isEligibleForRetry());
    }

    #[Test]
    public function replayFromDeadLetterClearsDlqAndResetsRetryCount(): void
    {
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        );

        $failed = $entry;
        for ($i = 0; $i < 5; $i++) {
            $failed = $failed->markAsFailed('boom', new \DateTimeImmutable('+1 hour'));
        }
        $dlq = $failed->markAsDeadLetter(new \DateTimeImmutable());

        $replayed = $dlq->replayFromDeadLetter();

        self::assertFalse($replayed->isDeadLetter());
        self::assertNull($replayed->getDeadLetterAt());
        self::assertSame(0, $replayed->getRetryCount());
        self::assertNull($replayed->getLastError());
        self::assertNull($replayed->getNextRetryAt());
        self::assertTrue($replayed->isEligibleForRetry());
    }

    #[Test]
    public function toArrayAndFromArrayRoundTripDeadLetterAt(): void
    {
        $stamp = new \DateTimeImmutable('2026-05-05 12:34:56');
        $entry = OutboxEntry::createForEvent(
            aggregateType: 'News',
            aggregateId: 'agg-1',
            eventType: 'E',
            eventPayload: '{}',
            topic: 't',
            routingKey: 'r',
        )->markAsDeadLetter($stamp);

        $rehydrated = OutboxEntry::fromArray($entry->toArray());

        self::assertTrue($rehydrated->isDeadLetter());
        self::assertEquals($stamp, $rehydrated->getDeadLetterAt());
    }

    #[Test]
    public function fromArrayHandlesMissingDeadLetterAtForBackwardsCompat(): void
    {
        // Rows from before the migration won't have dead_letter_at in the array
        $entry = OutboxEntry::fromArray([
            'id' => '11111111-1111-1111-1111-111111111111',
            'message_type' => 'EVENT',
            'aggregate_type' => 'News',
            'aggregate_id' => 'agg-1',
            'event_type' => 'E',
            'event_payload' => '{}',
            'topic' => 't',
            'routing_key' => 'r',
            'created_at' => '2026-05-05 00:00:00.000000',
            'published_at' => null,
            'retry_count' => 0,
            'last_error' => null,
            'next_retry_at' => null,
            'sequence_number' => 1,
            // dead_letter_at intentionally omitted
        ]);

        self::assertFalse($entry->isDeadLetter());
        self::assertNull($entry->getDeadLetterAt());
    }
}
