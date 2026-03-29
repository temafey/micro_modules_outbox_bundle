# MicroModule Outbox Bundle

Transactional Outbox Pattern bundle for reliable event delivery in event-sourced Symfony applications.

## Features

- **Transactional Outbox**: Domain events and task commands are written to an outbox table within the same database transaction, then published asynchronously
- **Broadway Integration**: Optional EventStore decorator that automatically captures events to the outbox
- **Dual Publishers**: Separate EventPublisher (RabbitMQ) and TaskPublisher with a unified OutboxPublisher orchestrator
- **OpenTelemetry Metrics**: Optional counters, histograms, and gauges for outbox operations
- **Feature Flag**: Runtime toggle via `OUTBOX_ENABLED` environment variable
- **Console Commands**: Background publisher poller and cleanup for expired entries
- **DDD Layering**: Clean Domain/Infrastructure separation with interfaces

## Architecture

```
Command Handler
    └── EventStore (wrapped by OutboxAwareEventStore)
            ├── Broadway EventStore (event persistence)
            └── OutboxRepository (outbox entry in same transaction)

Background Poller (PublishOutboxCommand)
    └── OutboxPublisher
            ├── EventPublisher → RabbitMQ
            └── TaskPublisher → Task Queue

Cleanup (CleanupOutboxCommand)
    └── OutboxRepository::deletePublishedBefore()
```

## Installation

```bash
composer require micro-module/outbox-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    MicroModule\Outbox\OutboxBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/micro_outbox.yaml
micro_outbox:
    connection: doctrine.dbal.write_connection  # DBAL connection for outbox table
    publisher:
        batch_size: 100           # Max entries per poll cycle
        poll_interval_ms: 1000    # Poll interval in milliseconds
    cleanup:
        retention_days: 30        # Days to keep published entries
        dead_letter_retention_days: 90  # Days to keep dead-letter entries
    broadway:
        enabled: true             # Enable OutboxAwareEventStore (requires broadway/broadway)
    metrics:
        enabled: false            # Enable OpenTelemetry metrics (requires open-telemetry/sdk)
```

## Database Schema

The outbox table must be created via migration:

```sql
CREATE TABLE outbox (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    message_type VARCHAR(20) NOT NULL,  -- 'event' or 'task'
    payload JSONB NOT NULL,
    headers JSONB DEFAULT '{}',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ,
    failed_at TIMESTAMPTZ,
    error_message TEXT
);

CREATE INDEX idx_outbox_status_created ON outbox (status, created_at);
```

## Console Commands

```bash
# Publish pending outbox entries (background poller)
bin/console outbox:publish --batch-size=100 --poll-interval=1000

# Cleanup old published entries
bin/console outbox:cleanup --retention-days=30 --dead-letter-retention-days=90
```

## Key Classes

| Class | Purpose |
|-------|---------|
| `OutboxBundle` | Symfony AbstractBundle with conditional loading |
| `OutboxEntryInterface` | Domain contract for outbox entries |
| `OutboxRepositoryInterface` | Domain contract for persistence |
| `DbalOutboxRepository` | DBAL implementation of outbox storage |
| `OutboxAwareEventStore` | Broadway EventStore decorator (captures events to outbox) |
| `OutboxAwareTaskProducer` | Task producer that writes to outbox instead of direct queue |
| `OutboxPublisher` | Orchestrates EventPublisher + TaskPublisher |
| `EventPublisher` | Publishes domain events to RabbitMQ |
| `TaskPublisher` | Publishes task commands to task queue |
| `OutboxFeatureFlag` | Runtime toggle via `OUTBOX_ENABLED` env var |
| `OpenTelemetryOutboxMetrics` | OTel counters/histograms for monitoring |
| `NullOutboxMetrics` | No-op metrics (default when OTel disabled) |

## Optional Dependencies

| Package | Purpose |
|---------|---------|
| `broadway/broadway` | Required for `OutboxAwareEventStore` and `BroadwayDomainEventSerializer` |
| `micro-module/enqueue` | Required for `EventPublisher` (RabbitMQ queue publishing) |
| `open-telemetry/sdk` | Required for `OpenTelemetryOutboxMetrics` |

## Requirements

- PHP 8.4+
- Symfony 7.0+ or 8.0+
- Doctrine DBAL 4.4+

## License

Proprietary
