# Changelog

All notable changes to `micro-module/outbox-bundle` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- **OBX2-DLQ-COLUMN**: Explicit dead-letter queue semantics on the outbox table. The publisher
  now stamps `dead_letter_at` (a new nullable `TIMESTAMP(6)` column) when `retry_count` crosses
  `--max-retries`, and `findUnpublished()` / `findUnpublishedByType()` exclude rows with
  `dead_letter_at IS NOT NULL`. The exclusion has moved from application-level
  `array_filter` to SQL — DLQ rows are now invisible to polling at the database boundary,
  with the application-level filter retained as defence in depth.

  - `OutboxEntry` gains `?DateTimeImmutable $deadLetterAt`, `getDeadLetterAt()`, `isDeadLetter()`,
    `markAsDeadLetter(\DateTimeImmutable)`, `replayFromDeadLetter()` (resets `retry_count`,
    `last_error`, `next_retry_at`, `dead_letter_at`).
  - `OutboxEntry::isEligibleForRetry()` returns `false` for DLQ entries.
  - `OutboxEntry::fromArray()` accepts payloads that omit `dead_letter_at` (backwards-compatible
    with rows persisted before the migration).

  Consuming projects must run a migration adding the column and a partial index. Reference
  migration: `news-mvp/Version20260505000001.php`.

- **OBX2-DLQ-INTERFACE**: `OutboxRepositoryInterface` gains six DLQ-aware methods:
  `markAsDeadLetter(string $id, \DateTimeImmutable $deadLetterAt): void`,
  `findDeadLetter(int $limit): array`,
  `countDeadLetter(): int`,
  `replayDeadLetter(string $id): bool`,
  `countDeadLetterBefore(\DateTimeImmutable $before): int`,
  `deleteDeadLetterBefore(\DateTimeImmutable $before, int $limit): int`.

  `getMetrics()` return type adds `'dlq_count' => int`.

  **BREAKING for downstream implementations** of `OutboxRepositoryInterface`. Stub
  implementations in test fixtures must add the new methods (see updated stubs in
  `tests/Infrastructure/OutboxAware*Test.php`). The bundled `DbalOutboxRepository`
  implements all new methods.

- **OBX2-DLQ-CMD**: New `app:outbox:dlq` command (`DlqOutboxCommand`) for inspecting and
  replaying dead-letter rows.

  - `bin/console app:outbox:dlq` — list 50 oldest DLQ rows in a table (id, type, aggregate,
    event, retries, dlq-at, last-error).
  - `bin/console app:outbox:dlq --limit=N` — adjust list size.
  - `bin/console app:outbox:dlq --count` — print only the DLQ row count (machine-friendly).
  - `bin/console app:outbox:dlq --replay=<uuid>` — clear `dead_letter_at` and reset
    `retry_count` to 0 so the next publisher poll picks up the row.

- **OBX2-DLQ-RETENTION**: `app:outbox:cleanup --dead-letter-retention=DAYS` purges DLQ rows
  whose `dead_letter_at` is older than the cutoff. Omitting the option preserves prior
  behaviour (DLQ rows are kept indefinitely).

  Recommended: schedule a cleanup worker (`expo-micro-news-outbox-cleanup` in news-mvp's
  `docker-compose.workers.yml`) that runs hourly with `--include-failed --dead-letter-retention=90`
  to match the `dead_letter_retention_days: 90` config knob.

- **OBX2-DLQ-WORKER** (news-mvp companion): hourly `expo-micro-news-outbox-cleanup` worker
  in `docker-compose.workers.yml` runs `app:outbox:cleanup --retention=30 --include-failed
  --max-retries=5 --dead-letter-retention=90` in a `while-true` loop. Healthcheck looks for
  the cleanup process or its `sleep 3600` between runs.

### Fixed

- **OBX2-DLQ-LOOP**: Poison-message tight loop in `app:outbox:publish`. Previously, when a row's
  `retry_count` reached `--max-retries` (default 5), the publisher would (a) still fetch the row
  (filter was `<=`), (b) fail to publish, and (c) skip `markAsFailed` (guard was `<`). The result
  was the same row being re-attempted every poll cycle with `retry_count` and `next_retry_at`
  frozen, burning RabbitMQ connection attempts and log volume indefinitely.

  The fix:
  - `PublishOutboxCommand::fetchPendingMessages()` filter is now strict `<` — a row whose
    `retry_count` has already reached the budget is excluded from polling.
  - The failure handler always calls `OutboxRepositoryInterface::markAsFailed()`, even when the
    row crosses the budget on this attempt. This guarantees `retry_count` is incremented past the
    threshold so subsequent polls correctly skip the row, and `last_error` / `next_retry_at`
    reflect the most recent failure.
  - When `retry_count` crosses the budget, the publisher emits a structured `critical`-level log
    entry (`Outbox message exceeded max retries, marked as dead-letter`) including
    `aggregate_type`, `aggregate_id`, `last_error`, and `error_type`, suitable for alerting.

  Regression tests: `tests/Console/PublishOutboxCommandTest.php`
  (`poisonMessageAtRetryBudgetIsExcludedFromPolling`,
  `failureAtBudgetMinusOneStillRecordsFailure`).

- **OBX2-DLQ-CLEANUP**: `app:outbox:cleanup --include-failed` did not actually delete dead-letter
  rows. The SQL used strict `retry_count > :max_retries`; combined with the publisher off-by-one
  above, `retry_count` never exceeded the threshold, so the cleanup was a no-op for poison
  messages. Additionally, the cleanup command hard-coded `5` instead of using the configured
  threshold.

  The fix:
  - `OutboxRepositoryInterface::countFailedExceedingRetries()` and `deleteFailedExceedingRetries()`
    now match `retry_count >= :max_retries` (semantics: "row has reached or exceeded the
    configured retry budget"). Documented in interface PHPDoc.
  - `CleanupOutboxCommand` exposes a new `--max-retries` option (default 5) that is threaded
    through to both methods, so the cleanup threshold matches the publisher's `--max-retries`.

  Regression test: `tests/Console/CleanupOutboxCommandTest.php`
  (`maxRetriesOptionIsForwardedToRepository`).

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
