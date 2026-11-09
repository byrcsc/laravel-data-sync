# Changelog

All notable changes to `byrcsc/laravel-data-sync` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0] - 2026-11-09

Database sources. A sync definition can now read rows from any connection in
`config/database.php` and run them through the same pipeline, ledger, and run
history as a file or an API. Every change is additive. No migration is needed
beyond the 2.0 upgrade migration.

### Added

- **Database sync definitions.** Extend `DatabaseSyncDefinition`, return a
  connection name from `connection()`, a base query from `query()`, and the
  columns that order rows uniquely from `keyColumns()`. The package reads the
  query in key-ordered pages of `pageSize()` rows, 1000 by default, writes them
  into one NDJSON snapshot, and processes it as a file. Composite keys page
  correctly.
- **Watermarks for database sources.** `watermark()` returns
  `Watermark::timestamp($column, overlapSeconds: N)` for a last-modified column
  on any driver, or `Watermark::rowVersion($column)` for a SQL Server
  `rowversion` column. The package captures the upper bound before it reads, so
  a row that changes during the read lands in the next run. A null watermark
  reads the whole query on every run, and the ledger skips an unchanged table.
- A changed watermark mode or column fails the fetch with an error that names
  the `sync_watermarks` row to delete.
- The fetch checks the source table's columns before it reads any rows, and
  fails with an error that names the field, key, or watermark column to fix. A
  binary column fails the run with the column's name.
- `SnapshotSyncDefinition`, the shared parent of `ApiSyncDefinition` and
  `DatabaseSyncDefinition`, with a `lockTimeout()` that sets how long a fetch
  holds its lock. It defaults to 300 seconds, the fixed value before.
- `sync:status` and `sync:doctor` warn when a snapshot definition's last runs
  all ended without `completed`, which means its watermark has stopped moving.
  `data-sync.stalled_watermark_runs` sets how many runs, 5 by default.
- `sync:doctor` checks each database definition's source table with one schema
  read, and reports a missing PDO extension with its install command.
- `make:sync --database` generates a database definition.
- **Definition providers.** `data-sync.syncs` also accepts a class that
  implements `Contracts\DefinitionProvider`. Its `definitions()` returns
  definitions at runtime, such as one per database an account connects on a
  multi-tenant platform. Each one has its own watermark, ledger, lock, and
  history. The package never calls a provider while the application boots. A
  name a provider returns may use only letters, digits, hyphens, and
  underscores.

### Changed

- Schedules are registered when the scheduler resolves `Schedule`, instead of
  on every application boot. `schedule:run`, `schedule:work`, and
  `schedule:list` see the same events as before.

### Fixed

- A JSON string bound for an attribute with a JSON cast (`array`, `json`,
  `object`, `collection`, and their encrypted and `AsArrayObject` or
  `AsCollection` forms) is now decoded before the model is filled, so the
  column holds one level of JSON encoding. Before, the cast encoded the string a
  second time and the column held a JSON string literal. This affected every
  nested object an API source returned. A value that is not valid JSON becomes a
  row failure that names the column.

## [2.0.0] - 2026-11-09

API sources. A sync definition can now pull records from an HTTP API and run
them through the same pipeline, ledger, and run history as a file. This release
also renames public classes, methods, a column, and a config key, which breaks
code written against 1.x. Follow "Upgrading from 1.0.0" below.

### Added

- **API sync definitions.** Extend `ApiSyncDefinition`, return the URL from
  `endpoint()`, and map fields as in a file sync. The package fetches every page
  into one NDJSON snapshot in the staging area and processes it as a file.
- `request()` adds authentication, headers, and query parameters.
  `nextPage()` returns the next page URL, and `records()` returns the records in
  a response. `timeout()`, `retries()`, and `retryDelay()` set the HTTP client
  limits, which default to 30 seconds, 3 retries, and 1000 ms.
- **Watermarks.** `watermarkFrom()` returns the value the next fetch resumes
  from, and `request()` receives it. The package stores it in the new
  `sync_watermarks` table and commits it only when the run completes, so the
  next fetch after a failed run asks for the same range again.
- Every fetch records a run, including an empty one. The ledger never skips an
  empty snapshot as a repeat, so a quiet feed still shows a completed zero-row
  run each time.
- A fetch that fails after its retries records a failed run, keeps the pages
  that arrived, and throws `ApiFetchException` with the definition name and
  endpoint. A `nextPage()` that returns a URL it already fetched stops with an
  error instead of looping.
- `make:sync --api` generates an API definition.
- `sync:doctor` reports a missing `sync_watermarks` table when a configured
  definition is an API definition.

### Changed

These renames break code written against 1.0.0. Each old name is gone, not
deprecated.

- `Definitions\Reader` is now `Definitions\Format`, so a definition imports the
  `Contracts\Reader` return type without an alias and returns `Format::csv()`.
- The `WritePath` enum is now `WriteStrategy`, the `sync_runs.write_path` column
  is now `write_strategy`, and `SyncRun::$write_path` follows it.
- `Data\SyncReport` is now `Data\RunResult`. Its `writePath` property is now
  `writeStrategy`. `RunReport::$runs` holds `RunResult` objects.
- `Source::archiveTo()`, `moveSourceTo()`, and `deleteFromSource()` are now
  `copyTo()`, `moveTo()`, and `deleteOriginal()`. The getters
  `sourceArchivePath()`, `sourceMovePath()`, `deletesSource()`, and
  `directory()` are now `copyPath()`, `movePath()`, `deletesOriginal()`, and
  `pathName()`.
- `TransferDefinition::matchFilename()` is now `filenamePattern()`.
- `Field::source()` is now `Field::from()`, matching `Field::make($name, from:)`.
- The `data-sync.store_failed_payloads` config is now
  `data-sync.capture_failed_payloads`, and
  `SyncDefinition::withoutPayloadCapture()` is now
  `withoutFailedPayloadCapture()`. A published config that still has the old
  key fails at boot with a message that names the new one.

### Upgrading from 1.0.0

1. Rename the methods and classes above in your definitions.
2. Rename `store_failed_payloads` in `config/data-sync.php`.
3. Publish the migrations again and run `php artisan migrate`. The new
   `upgrade_data_sync_tables_to_v2` migration adds the `sync_watermarks`
   table and renames `write_path` to `write_strategy`.
4. Let the queue drain before you deploy. A `ProcessChunkJob` queued by 1.0.0
   carries a `selectedRows` property that 2.0 renamed to `keptRows`, and it
   fails when a 2.0 worker picks it up.

## [1.0.0] - 2026-08-01

First release. File-based data synchronization for Laravel, from discovery
through to the audit trail.

### Added

- **Sync definitions** — class-based definitions with a declarative field
  mapping, per-row validation rules, and configurable write, duplicate, and
  unknown-column policies. `prepare()`, `beforeSave()`, and `afterSave()` hooks,
  a `maxErrors()` circuit breaker, and a name derived from the class so
  `ProductsSync` runs as `products`.
- **Streaming readers** — CSV and other delimited text, XLSX, NDJSON,
  fixed-width columns, and JSON that selects its row array from a configured
  document path. Header fail-fast and unknown-column policies apply across all
  of them.
- **Discovery and the process-once ledger** — source discovery across local,
  cloud, FTP, and SFTP disks, staging onto a working disk, and a SHA-256 ledger
  keyed on file contents rather than filename, so reprocessing is a no-op and a
  one-byte change is a new file. Only a successful run blocks reprocessing.
- **Processing modes** — queued file and chunk processing fanned out through
  `Bus::batch()` with configurable global and per-sync queue routing, an
  in-process mode for small feeds, and an `atomic()` whole-file mode that trades
  parallelism for all-or-nothing semantics.
- **Archiving and source cleanup** — checksum-verified archives for completed
  files, preserved copies for failed ones, and configurable source archive,
  move, and delete actions.
- **Run history** — run lifecycle events, detailed counters, failure summaries,
  optional failed-row payload capture, and a new run for every forced processing
  attempt. `sync:status` surfaces recent runs, queued batch progress, write
  paths, and failed-row detail.
- **Dry runs** — `sync:run --dry-run` reports without writing models, running
  write hooks, archiving files, or cleaning up sources.
- **Retries** — `sync:retry` reprocesses a finished run from its preserved file,
  and `--failed-rows` replays only the captured failed-row payloads.
- **Retention** — `sync:prune` with independent windows for runs, failed rows,
  archived files, and preserved failed files. The `sync_files` ledger is never
  pruned.
- **Scheduling** — schedule registration from definitions and from per-sync
  `overrides`, with `register_schedules` to opt out entirely.
- **File transfers** — filename pattern matching, templated destination paths,
  checksum verification, existing-file policies, an `afterTransfer()` hook, and
  optional model linking from filename metadata.
- **Health check** — `sync:doctor` checks tables, definitions, connections,
  source and destination reachability, working-disk writability, cache locks,
  `matchOn` indexes, and stuck runs, with `--strict` to fail on warnings.
- **Scaffolding** — `make:sync` generates sync and transfer definitions.
- **Compatibility** — PHP 8.3 and 8.4, Laravel 12 and 13, database-aware tests
  for SQLite, MySQL, and PostgreSQL, PHPStan at maximum level with no baseline,
  and Pint.
- **Workbench demo application** — `workbench/` is a bootable Laravel app that
  installs the package the way a real application would and drives the full
  loop: a supplier drop directory, a run against a live `queue:work` worker and
  in process, batch progress through `sync:status`, the ledger refusing a second
  pass, dry runs, `sync:doctor`, and `make:sync`. Set it up with
  `composer build`; see
  `workbench/README.md`. Development tooling only — it is excluded from the
  Composer dist archive and is not covered by CI.

[Unreleased]: https://github.com/byrcsc/laravel-data-sync/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/byrcsc/laravel-data-sync/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/byrcsc/laravel-data-sync/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/byrcsc/laravel-data-sync/releases/tag/v1.0.0
