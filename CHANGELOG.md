# Changelog

All notable changes to `micro-module/outbox-bundle` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

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
