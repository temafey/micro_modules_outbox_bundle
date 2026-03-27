<?php

declare(strict_types=1);

namespace MicroModule\Outbox\Infrastructure\Observability;

use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Factory for creating OpenTelemetry Tracer instances.
 *
 * Thin local interface to avoid coupling to the observability-bundle.
 * When observability-bundle is installed, its implementation is used.
 */
interface TracerFactoryInterface
{
    public function create(string $name, ?string $version = null): TracerInterface;
}
