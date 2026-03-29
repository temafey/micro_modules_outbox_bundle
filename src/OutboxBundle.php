<?php

declare(strict_types=1);

namespace MicroModule\Outbox;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony bundle for the Transactional Outbox Pattern.
 *
 * Provides reliable event delivery by writing domain events and task commands
 * to an outbox table within the same database transaction, then publishing
 * them asynchronously via a background poller.
 *
 * Features:
 * - Core outbox repository, feature flag, publishers, and console commands
 * - Optional Broadway integration (EventStore decorator, serializer)
 * - Optional OpenTelemetry metrics (counters, histograms, gauges)
 *
 * Configuration example:
 *   micro_outbox:
 *     publisher:
 *       batch_size: 100
 *       poll_interval_ms: 1000
 *     cleanup:
 *       retention_days: 30
 *       dead_letter_retention_days: 90
 *     broadway:
 *       enabled: true
 *     metrics:
 *       enabled: false
 */
final class OutboxBundle extends AbstractBundle
{
    protected string $extensionAlias = 'micro_outbox';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->canBeDisabled()
            ->children()
                ->scalarNode('connection')
                    ->defaultValue('doctrine.dbal.default_connection')
                    ->info('Doctrine DBAL connection service ID for the outbox table')
                ->end()
                ->arrayNode('publisher')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')
                            ->defaultValue(100)
                            ->min(1)
                            ->max(10000)
                            ->info('Maximum number of outbox entries to publish per poll cycle')
                        ->end()
                        ->integerNode('poll_interval_ms')
                            ->defaultValue(1000)
                            ->min(100)
                            ->max(60000)
                            ->info('Poll interval in milliseconds for the background publisher')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cleanup')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('retention_days')
                            ->defaultValue(30)
                            ->min(1)
                            ->info('Days to retain published outbox entries before cleanup')
                        ->end()
                        ->integerNode('dead_letter_retention_days')
                            ->defaultValue(90)
                            ->min(1)
                            ->info('Days to retain dead-letter (max retries exceeded) entries')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('broadway')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable Broadway EventStore decorator (auto-detected: requires broadway/broadway)')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metrics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable OpenTelemetry metrics collection (requires open-telemetry/sdk)')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Create a service alias for the configured DBAL connection
        $builder->setAlias('micro_outbox.dbal_connection', $config['connection']);

        // Set container parameters from config
        $container->parameters()->set('micro_outbox.publisher.batch_size', $config['publisher']['batch_size']);
        $container->parameters()->set('micro_outbox.publisher.poll_interval_ms', $config['publisher']['poll_interval_ms']);
        $container->parameters()->set('micro_outbox.cleanup.retention_days', $config['cleanup']['retention_days']);
        $container->parameters()->set('micro_outbox.cleanup.dead_letter_retention_days', $config['cleanup']['dead_letter_retention_days']);

        // Conditionally load Broadway services
        if ($config['broadway']['enabled'] && class_exists(\Broadway\EventSourcing\EventSourcingRepository::class)) {
            $container->import('../config/broadway.php');
        }

        // Conditionally load OTel metrics services
        if ($config['metrics']['enabled'] && class_exists(\OpenTelemetry\SDK\Metrics\MeterProvider::class)) {
            $container->import('../config/metrics.php');
        }
    }
}
