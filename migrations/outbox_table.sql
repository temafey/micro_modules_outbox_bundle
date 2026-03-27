-- Transactional Outbox Table
-- Creates the outbox table for reliable message delivery in event-sourced systems.
-- Supports both domain events and task commands.

CREATE TABLE IF NOT EXISTS outbox (
    id UUID PRIMARY KEY,
    message_type VARCHAR(10) NOT NULL,          -- Discriminator: EVENT or TASK
    aggregate_type VARCHAR(255) NOT NULL,        -- e.g., 'News', 'User'
    aggregate_id UUID NOT NULL,                  -- UUID of the entity
    event_type VARCHAR(255) NOT NULL,            -- Event/command class name
    event_payload JSONB NOT NULL,                -- Serialized payload
    topic VARCHAR(255) NOT NULL,                 -- Target exchange/topic
    routing_key VARCHAR(255) NOT NULL,           -- Message routing key
    created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    published_at TIMESTAMP(6) WITHOUT TIME ZONE NULL,
    retry_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    next_retry_at TIMESTAMP(6) WITHOUT TIME ZONE NULL,
    sequence_number BIGINT NOT NULL DEFAULT 0,   -- Monotonic sequence for FIFO ordering
    CONSTRAINT chk_message_type CHECK (message_type IN ('EVENT', 'TASK'))
);

-- Primary index: polling unpublished messages (FIFO order)
CREATE INDEX IF NOT EXISTS idx_outbox_unpublished
    ON outbox (published_at, next_retry_at, sequence_number)
    WHERE published_at IS NULL;

-- Cleanup: find old published messages for deletion
CREATE INDEX IF NOT EXISTS idx_outbox_cleanup
    ON outbox (published_at)
    WHERE published_at IS NOT NULL;

-- Aggregate lookup: debug specific entities
CREATE INDEX IF NOT EXISTS idx_outbox_aggregate
    ON outbox (aggregate_type, aggregate_id);

-- Message type filter: events vs tasks
CREATE INDEX IF NOT EXISTS idx_outbox_message_type
    ON outbox (message_type)
    WHERE published_at IS NULL;

-- Event type monitoring
CREATE INDEX IF NOT EXISTS idx_outbox_event_type
    ON outbox (event_type);

-- Retry monitoring: stuck/failing messages
CREATE INDEX IF NOT EXISTS idx_outbox_failed
    ON outbox (retry_count, last_error)
    WHERE retry_count > 0 AND published_at IS NULL;

-- Sequence for ordered message processing
CREATE SEQUENCE IF NOT EXISTS outbox_sequence_seq START WITH 1 INCREMENT BY 1;

COMMENT ON TABLE outbox IS 'Transactional outbox for reliable message delivery (events and tasks)';
COMMENT ON COLUMN outbox.message_type IS 'Discriminator: EVENT for domain events, TASK for commands';
COMMENT ON COLUMN outbox.sequence_number IS 'Monotonically increasing sequence for ordered processing';
