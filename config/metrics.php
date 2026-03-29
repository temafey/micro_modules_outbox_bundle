<?php

declare(strict_types=1);

use MicroModule\Outbox\Infrastructure\Metrics\OpenTelemetryOutboxMetrics;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // --- OpenTelemetry Metrics Implementation ---
    $services->set(OpenTelemetryOutboxMetrics::class);

    // Override the default NullOutboxMetrics alias
    $services->alias(OutboxMetricsInterface::class, OpenTelemetryOutboxMetrics::class);
};
