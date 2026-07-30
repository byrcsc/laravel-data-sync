# Laravel Data Sync

Stream files into Laravel models with auditable, resumable data syncs and
transfers.

> [!IMPORTANT]
> The package is under active development and does not have a stable release
> yet. Its public API is not ready for production use.

## What this package is for

Laravel Data Sync is being built for recurring file-based integrations:

- discover files on local, cloud, FTP, or SFTP storage;
- stream CSV, XLSX, JSON, NDJSON, and fixed-width records;
- map and validate rows before writing Eloquent models;
- process large imports inline or through Laravel queues and batches;
- preserve a process-once ledger, run history, and failed-row diagnostics;
- transfer files using filename metadata and checksum verification.

The package deliberately does not cover API connectors, two-way
synchronization, OCR, or deleting application records that disappear from a
feed.

JSON imports read one complete document and select the row array from a
configured path. Multi-gigabyte single-document JSON is out of scope; use
NDJSON when rows need to stream independently.

## Development

The local checks mirror CI:

```bash
composer install
composer test
composer analyse
vendor/bin/pint --test
```

PHPStan runs at level max with no baseline. Tests use SQLite locally and run
against MySQL and PostgreSQL in CI.

## License

The MIT License. See [LICENSE.md](LICENSE.md).
