# Changelog

All notable changes to `byrcsc/laravel-data-sync` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/byrcsc/laravel-data-sync/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/byrcsc/laravel-data-sync/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/byrcsc/laravel-data-sync/releases/tag/v1.0.0
