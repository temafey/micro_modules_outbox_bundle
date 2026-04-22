# Changelog

All notable changes to `micro-module/outbox-bundle` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.3.0] — 2026-04-22

### Added

- **OBX2-FF**: Optional `?OutboxFeatureFlag $featureFlag = null` constructor parameter on
  `OutboxAwareEventStore` (`src/Infrastructure/OutboxAwareEventStore.php`). When provided, the
  decorator's initial `$enabled` state is derived from `$featureFlag->isEnabled()`. When omitted,
  the decorator defaults to enabled — preserving existing default behaviour for all 0.1.x / 0.2.x
  callers. Runtime `enable()` / `disable()` / `isEnabled()` methods continue to override the
  flag-derived initial state.
  `config/broadway.php` now wires the `OutboxFeatureFlag` service (already registered via
  `config/services.php:39-40` as `OutboxFeatureFlag::fromEnv()`) into the decorator's args.
  Downstream projects inherit the `OUTBOX_ENABLED` environment variable as a runtime kill-switch
  with no additional wiring.
  Unit tests added: `tests/Infrastructure/OutboxAwareEventStoreTest.php` (4 scenarios: flag-enabled
  writes outbox row, flag-disabled skips outbox, default-no-flag is enabled, runtime disable()
  overrides initial flag state).

- **VP-7b**: Introduced `CommandFactory` (`src/Application/Task/CommandFactory.php`) for deserializing
  Broadway `Serializable` command objects from outbox TASK payloads.  Mirrors the `EventPublisher`
  class-map resolution pattern: alias → FQCN lookup with eager validation at container build time.
  `TaskPublisher` updated to inject `CommandFactory` and `Broadway\CommandHandling\CommandBus`; it
  deserializes the command via `CommandFactory::deserialize()` and dispatches via
  `CommandBus::dispatch()`.  JSON decode errors and factory failures are caught and re-thrown as
  `OutboxPublishException::deserializationFailed` to preserve the outbox poller's retry loop.
  `CommandFactory` is registered in `config/services.php` with an empty default classMap; consuming
  projects populate it via their own DI configuration.
  Unit tests added: `tests/Application/Task/CommandFactoryTest.php` (5 scenarios) and
  `tests/Infrastructure/Publisher/TaskPublisherTest.php` (5 scenarios, covers 3 TaskPublisher cases).

- **VP-7a**: `OutboxAwareTaskProducer` now implements `SagaCommandQueueInterface` from `micro-module/saga`.
  Added `enqueue(string $commandClass, array $payload): void` method that writes a TASK outbox entry
  atomically within the current database transaction. Existing `sendCommand()` and `sendEvent()` methods
  are untouched for full backward compatibility.
  The `micro-module/saga` package is now a `require` dependency (DIP direction: outbox-bundle → saga).
