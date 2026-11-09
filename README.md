# Laravel Data Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-data-sync.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-data-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-data-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-data-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-data-sync/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-data-sync/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-data-sync.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-data-sync)

An external system drops a file on your server: a CRM export, a supplier price
list, a bank statement. It has to end up in your tables. Laravel Data Sync turns
that into a class that names the source, the format, the model, the field
mapping, and the columns to match on.

The package handles discovery, reading, validation, queued writes, and the audit
trail. A process-once ledger means the same file never lands twice. Your
application keeps ownership of its models, its business rules, and whatever it
does with the data afterwards.

**[Read the full documentation](https://docs.rcsc.dev/laravel-data-sync/)** for
definitions, readers, write policies, queued execution, transfers, retries,
retention, and every Artisan command.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

## Installation

Install the package, publish its configuration and migration, and migrate:

```bash
composer require byrcsc/laravel-data-sync
php artisan vendor:publish --tag="data-sync-config"
php artisan vendor:publish --tag="data-sync-migrations"
php artisan migrate
```

Publish the configuration before the migration when you want custom table names;
the migration reads them from `data-sync.tables`.

Queued syncs fan chunks out through `Bus::batch()`, so the framework's
`job_batches` table must exist. FTP and SFTP sources need a Flysystem adapter
(`league/flysystem-ftp` or `league/flysystem-sftp-v3`), neither of which is
installed by default.

Run `php artisan sync:doctor` after installing. It checks tables, disks, source
reachability, cache locks, and `matchOn` indexes before your first real feed
arrives. See the
[installation guide](https://docs.rcsc.dev/laravel-data-sync/v1/installation)
for disks, connections, and queue routing.

## Quick start

Generate a definition:

```bash
php artisan make:sync ProductsSync
```

Fill in the source, the format, the model, the fields, and the match columns:

```php
namespace App\Syncs;

use App\Models\Product;
use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Format;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;

final class ProductsSync extends SyncDefinition
{
    public function source(): Source
    {
        return Source::disk('incoming')->path('products');
    }

    public function format(): Reader
    {
        return Format::csv();
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            Field::make('sku', from: 'SKU'),
            Field::make('name', from: 'Description'),
            Field::make('price_cents', from: 'Price')
                ->transform(fn (string $value): int => (int) round((float) $value * 100)),
        ];
    }

    public function matchOn(): array
    {
        return ['sku'];
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:32'],
            'price_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

Register it in `config/data-sync.php` and run it:

```php
'syncs' => [
    App\Syncs\ProductsSync::class,
],
```

```bash
php artisan sync:run products          # queued
php artisan sync:run products --now    # in this process
php artisan sync:status
```

The name is derived from the class: `ProductsSync` becomes `products`.

## What is included

- Class-based sync definitions with a declarative field mapping, per-row
  validation rules, and configurable write, duplicate, and unknown-column
  policies.
- Streaming readers for CSV and other delimited text, XLSX, JSON, NDJSON, and
  fixed-width files.
- Queued processing that fans a file's chunks out through `Bus::batch()`, plus
  an in-process mode for small feeds and an `atomic()` whole-file mode.
- A SHA-256 ledger that makes reprocessing a no-op, checksum-verified archives
  for completed files, and preserved copies for failed ones.
- Run history with counters, batch progress, failed-row records, and an error
  summary, surfaced through `sync:status`.
- `sync:retry` for a whole finished run, or `--failed-rows` to replay only the
  captured failed-row payloads.
- File transfers with filename-pattern metadata, templated destination paths,
  checksum verification, and optional model linking.
- Schedule registration, retention pruning, and a `sync:doctor` health check for
  the whole installation.

## Important behavior

- File identity is the SHA-256 checksum of the contents. Renaming a file does not
  make it new; changing one byte does.
- Only a successful run blocks reprocessing. A failed checksum is retried on the
  next run without `--force`.
- Chunks commit independently. Only `atomic()` gives a file all-or-nothing
  semantics, at the cost of parallelism.
- Model events fire only with the Eloquent write strategy, which a definition
  opts into by declaring `beforeSave()` or `afterSave()`.
- Package events are dispatched synchronously and never implement `ShouldQueue`.
  Queue your listeners.
- Multi-server deployments need a shared staging disk. A local staging disk is a
  single-machine configuration.
- `--dry-run` skips `prepare()`, so its counts are a preview and not a guarantee.
- `Format::json()` reads a whole document into memory. Use NDJSON for feeds that
  need to stream.
- The `sync_files` ledger is never pruned; runs, failed rows, and archived files
  are. `sync:prune` does not sweep the staging disk, so successful runs leave
  their staged copy behind.
- Rows that fail are recorded and the run continues. `maxErrors()` is the only
  thing that stops a bad feed part way.
- An API definition's watermark advances only when a run lands in `completed`. A
  failed or partially failed run leaves it alone, so the next fetch asks the
  remote system for the same window again. A fetch that returns no records is
  still a completed run with zero rows, so a quiet feed shows up in
  `sync:status` and its watermark advances. Dry runs never advance it.

### Acknowledging records to a remote system

The package pulls. Telling a remote system that a record has landed is the host
application's call to make, so it hangs off the events and hooks the package
already fires:

```php
// A single record, as it is written.
public function afterSave(Model $model, array $row): void
{
    Http::withToken(config('services.middleware.token'))
        ->patch("https://middleware.example.com/api/bookings/{$model->booking_no}", [
            'status' => 'received',
        ]);
}

// Or once per run, from a listener on SyncCompleted.
public function handle(SyncCompleted $event): void
{
    if ($event->run->status !== RunStatus::Completed) {
        return;
    }

    AcknowledgeBookings::dispatch($event->run->id);
}
```

Those calls are the host application's own HTTP traffic. They are not recorded
in `sync_runs`, and a failure in one does not fail the run that triggered it.

## Out of scope

The package draws its edges deliberately. What follows describes what it sets
out to do rather than what it might do later: treat none of it as planned work,
and none of it as ruled out forever.

- **Model pruning for absent rows.** The package never deletes application
  records that stop appearing in a feed. It is written for incremental and delta
  feeds rather than full snapshots.
- **PDF and OCR extraction.** Transfers move PDFs; nothing reads inside them.
- **AI-assisted extraction.** Mapping is declarative and deterministic.
- **Two-way sync.** Data flows into models. Sending anything to a remote system
  is the host application's job; see the acknowledgement recipe below.
- **Webhooks and inbound push.** An API definition polls on a schedule. Nothing
  listens for a remote system to call the application.
- **OAuth token refresh.** `request()` receives a `PendingRequest`, so a host
  application applies whatever credentials it already manages.
- **Merge semantics for captured JSON.** `UnknownColumnPolicy::Capture` overwrites
  the target attribute; it does not merge with what is already there.
- **Retry from a date.** `sync:retry` takes a run, not a time range.
- **Glob and pattern discovery.** A source names either a directory, including
  files in its subdirectories, or a single file. There is no filtering by name
  or extension.

## Documentation

- [Installation and setup](https://docs.rcsc.dev/laravel-data-sync/v1/installation)
- [Quick start](https://docs.rcsc.dev/laravel-data-sync/v1/quick-start)
- [Matching and writing](https://docs.rcsc.dev/laravel-data-sync/v1/matching-and-writing)
- [Running a sync](https://docs.rcsc.dev/laravel-data-sync/v1/running-syncs)
- [File transfers](https://docs.rcsc.dev/laravel-data-sync/v1/file-transfers)
- [Failures and retries](https://docs.rcsc.dev/laravel-data-sync/v1/failures-and-retries)
- [Configuration](https://docs.rcsc.dev/laravel-data-sync/v1/configuration)
- [Troubleshooting](https://docs.rcsc.dev/laravel-data-sync/v1/troubleshooting)

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

`workbench/` is a bootable demo application that installs the package the way a
real application would. `composer build` sets it up; see
[workbench/README.md](workbench/README.md) for the demo loop.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

- Upgrading within `1.x` is safe. Nothing you use will break.
- Only a new major version, like `2.0.0`, can break your code.
- If the README or the documentation describes it, it is safe to build on.
  If they don't, treat it as internal and expect it to change.

Bug fixes go into the newest version only. To get a fix, upgrade to it.

## Questions and issues

- **Stuck, or have an idea?** Start a
  [discussion](https://github.com/byrcsc/laravel-data-sync/discussions). Usage
  questions and feature ideas both live there.
- **Found a bug you can reproduce?**
  [Open an issue](https://github.com/byrcsc/laravel-data-sync/issues). A failing
  test is the fastest way to a fix, and a short reproduction is the next best
  thing.
- **Found a security problem?** Please don't open a public issue. See
  [SECURITY.md](SECURITY.md) for how to report it privately.
- **Planning a pull request?** [CONTRIBUTING.md](CONTRIBUTING.md) covers the
  setup and the three checks it needs to pass.

This package is maintained by one person, so replies can take a while.
Everything gets read.

## Credits

- [Ryan Catapang](https://github.com/byrcsc)
- [All contributors](https://github.com/byrcsc/laravel-data-sync/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).
