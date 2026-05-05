<?php

declare(strict_types=1);

use MicroModule\Outbox\Application\Task\CommandFactory;
use MicroModule\Outbox\Console\CleanupOutboxCommand;
use MicroModule\Outbox\Console\DlqOutboxCommand;
use MicroModule\Outbox\Console\PublishOutboxCommand;
use MicroModule\Outbox\Domain\OutboxRepositoryInterface;
use MicroModule\Outbox\Infrastructure\DbalOutboxRepository;
use MicroModule\Outbox\Infrastructure\Metrics\NullOutboxMetrics;
use MicroModule\Outbox\Infrastructure\Metrics\OutboxMetricsInterface;
use MicroModule\Outbox\Infrastructure\OutboxAwareProducerInterface;
use MicroModule\Outbox\Infrastructure\OutboxAwareTaskProducer;
use MicroModule\Outbox\Infrastructure\OutboxFeatureFlag;
use MicroModule\Outbox\Infrastructure\Publisher\EventPublisher;
use MicroModule\Outbox\Infrastructure\Publisher\OutboxPublisher;
use MicroModule\Outbox\Infrastructure\Publisher\OutboxPublisherInterface;
use MicroModule\Outbox\Infrastructure\Publisher\TaskPublisher;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // --- Repository ---
    // Connection is aliased in loadExtension() based on the 'connection' config
    $services->set(DbalOutboxRepository::class)
        ->args([
            service('micro_outbox.dbal_connection'),
        ]);

    $services->alias(OutboxRepositoryInterface::class, DbalOutboxRepository::class)
        ->public();

    // --- Feature Flag ---
    $services->set(OutboxFeatureFlag::class)
        ->factory([OutboxFeatureFlag::class, 'fromEnv']);

    // --- Metrics (default: null / no-op) ---
    $services->set(NullOutboxMetrics::class);

    $services->alias(OutboxMetricsInterface::class, NullOutboxMetrics::class);

    // --- Command Factory (for TaskPublisher deserialization) ---
    // Projects populate $classMap via DI config (see TASK-07-01).
    $services->set(CommandFactory::class)
        ->args(['$classMap' => []])
        ->public();

    // --- Publishers ---
    $services->set(EventPublisher::class);

    // TaskPublisher requires Broadway\CommandHandling\CommandBus.
    // Broadway registers this as 'broadway.command_handling.simple_command_bus'
    // (an event-dispatching wrapper). We wire it explicitly by service ID so
    // that autowiring against the interface is not required.
    $services->set(TaskPublisher::class)
        ->args([
            '$commandBus' => service('broadway.command_handling.simple_command_bus'),
        ]);

    $services->set(OutboxPublisher::class)
        ->args([
            '$eventPublisher' => service(EventPublisher::class),
            '$taskPublisher' => service(TaskPublisher::class),
        ]);

    $services->alias(OutboxPublisherInterface::class, OutboxPublisher::class);

    // --- Task Producer Decorator ---
    $services->set(OutboxAwareTaskProducer::class);

    $services->alias(OutboxAwareProducerInterface::class, OutboxAwareTaskProducer::class)
        ->public();

    // --- Console Commands ---
    $services->set(PublishOutboxCommand::class)
        ->tag('console.command');

    $services->set(CleanupOutboxCommand::class)
        ->tag('console.command');

    $services->set(DlqOutboxCommand::class)
        ->tag('console.command');
};
