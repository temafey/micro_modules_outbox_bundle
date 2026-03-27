<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure;

/**
 * Feature flag for outbox pattern.
 *
 * Allows runtime enabling/disabling of outbox functionality
 * for gradual rollout or emergency rollback.
 *
 * @see docs/tasks/phase-14-transactional-outbox/TASK-14.2-eventstore-decorator.md
 */
final readonly class OutboxFeatureFlag
{
    public function __construct(
        private bool $enabled,
    ) {
    }

    /**
     * Check if outbox pattern is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Create feature flag from environment variable.
     *
     * Reads OUTBOX_ENABLED environment variable (defaults to true).
     */
    public static function fromEnv(): self
    {
        return new self(enabled: filter_var(getenv('OUTBOX_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
    }
}
