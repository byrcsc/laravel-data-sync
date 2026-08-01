# Changelog

All notable changes to `byrcsc/laravel-data-sync` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/byrcsc/laravel-data-sync/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/byrcsc/laravel-data-sync/releases/tag/v1.0.0
