# Changelog

All notable changes to `laravel-data-sync` will be documented in this file.

## Unreleased

- Added queued file and chunk processing with configurable global and per-sync queue routing.
- Added dry-run reports through `sync:run --dry-run` without writing models, running write hooks, archiving files, or cleaning up sources.
- Added checksum-verified archives for completed files, preserved copies for failed files, and configurable source archive, move, and delete actions.
- Added run lifecycle events, detailed counters and failure summaries, optional failed-row payload capture, and a new run for every forced processing attempt.
- Added `sync:status` for recent run history, queued batch progress, write paths, and failed-row details.

## 0.2.0 - 2026-07-29

- Added the sync definition and field DSL.
- Added streaming CSV and delimited-file reading.
- Added source discovery, staging, and process-once tracking.
- Added synchronous end-to-end data synchronization.
