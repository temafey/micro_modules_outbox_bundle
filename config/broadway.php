<?php

declare(strict_types=1);

use MicroModule\Outbox\Domain\DomainEventSerializerInterface;
use MicroModule\Outbox\Infrastructure\BroadwayDomainEventSerializer;
use MicroModule\Outbox\Infrastructure\OutboxAwareEventStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // --- Broadway Domain Event Serializer ---
    $services->set(BroadwayDomainEventSerializer::class)
        ->args([
            '$payloadSerializer' => service('broadway.serializer.payload'),
            '$metadataSerializer' => service('broadway.serializer.metadata'),
        ]);

    $services->alias(DomainEventSerializerInterface::class, BroadwayDomainEventSerializer::class);

    // --- EventStore Decorator ---
    $services->set(OutboxAwareEventStore::class)
        ->decorate('broadway.event_store', priority: -10)
        ->args([
            '$inner' => service('.inner'),
        ]);
};
