<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Tests\Infrastructure;

use MicroModule\Outbox\Infrastructure\OutboxFeatureFlag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxFeatureFlag::class)]
final class OutboxFeatureFlagTest extends TestCase
{
    #[Test]
    public function constructorSetsEnabledState(): void
    {
        $enabled = new OutboxFeatureFlag(true);
        self::assertTrue($enabled->isEnabled());

        $disabled = new OutboxFeatureFlag(false);
        self::assertFalse($disabled->isEnabled());
    }

    #[Test]
    public function fromEnvDefaultsToTrue(): void
    {
        // Clear any existing env var
        putenv('OUTBOX_ENABLED');

        $flag = OutboxFeatureFlag::fromEnv();

        self::assertTrue($flag->isEnabled());
    }

    #[Test]
    public function fromEnvReadsFalse(): void
    {
        putenv('OUTBOX_ENABLED=false');

        try {
            $flag = OutboxFeatureFlag::fromEnv();
            self::assertFalse($flag->isEnabled());
        } finally {
            putenv('OUTBOX_ENABLED');
        }
    }

    #[Test]
    public function fromEnvReadsTrue(): void
    {
        putenv('OUTBOX_ENABLED=true');

        try {
            $flag = OutboxFeatureFlag::fromEnv();
            self::assertTrue($flag->isEnabled());
        } finally {
            putenv('OUTBOX_ENABLED');
        }
    }

    #[Test]
    public function fromEnvReads1AsTrue(): void
    {
        putenv('OUTBOX_ENABLED=1');

        try {
            $flag = OutboxFeatureFlag::fromEnv();
            self::assertTrue($flag->isEnabled());
        } finally {
            putenv('OUTBOX_ENABLED');
        }
    }

    #[Test]
    public function fromEnvReads0AsTrueDueToFallback(): void
    {
        // Note: '0' is falsy in PHP, so ?: 'true' replaces it with 'true'.
        // To disable outbox via env, use OUTBOX_ENABLED=false (not 0).
        putenv('OUTBOX_ENABLED=0');

        try {
            $flag = OutboxFeatureFlag::fromEnv();
            self::assertTrue($flag->isEnabled());
        } finally {
            putenv('OUTBOX_ENABLED');
        }
    }
}
