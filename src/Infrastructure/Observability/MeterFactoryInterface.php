<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Observability;

use OpenTelemetry\API\Metrics\MeterInterface;

/**
 * Factory for creating OpenTelemetry Meter instances.
 *
 * Thin local interface to avoid coupling to the observability-bundle.
 * When observability-bundle is installed, its implementation is used.
 */
interface MeterFactoryInterface
{
    public function create(string $name, ?string $version = null): MeterInterface;
}
