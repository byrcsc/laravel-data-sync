# Laravel Data Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-data-sync.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-data-sync)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-data-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-data-sync/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-data-sync/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-data-sync/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-data-sync.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-data-sync)

An external system drops a file on your server: a CRM export, a supplier price
list, a bank statement. It has to end up in your tables. Laravel Data Sync turns
that into a class that names the source, the format, the model, the field
mapping, and the columns to match on. When the data sits behind an HTTP API
or in another database instead, an API or database definition fetches it on a
schedule and feeds it through the same pipeline.

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

### Syncing from an API

Generate an API definition:

```bash
php artisan make:sync OrdersSync --api
```

An API definition replaces `source()` and `format()` with the endpoint and how
to read it. The model, fields, match columns, and rules work as in a file sync:

```php
namespace App\Syncs;

use App\Models\Order;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class OrdersSync extends ApiSyncDefinition
{
    public function endpoint(): string
    {
        return 'https://middleware.example.com/api/orders';
    }

    public function request(PendingRequest $http, mixed $watermark): PendingRequest
    {
        return $http->withToken(config('services.middleware.token'))
            ->withQueryParameters(['updated_since' => $watermark ?? '1970-01-01']);
    }

    public function nextPage(Response $response): ?string
    {
        return $response->json('meta.next_page_url');
    }

    public function records(Response $response): iterable
    {
        return $response->json('data') ?? [];
    }

    public function watermarkFrom(Response $response, iterable $records): mixed
    {
        return $response->json('meta.max_updated_at');
    }

    public function model(): string
    {
        return Order::class;
    }

    public function fields(): array
    {
        return [
            Field::make('order_no', from: 'number'),
            Field::make('status', from: 'status'),
        ];
    }

    public function matchOn(): array
    {
        return ['order_no'];
    }

    public function schedule(Event $event): void
    {
        $event->everyFifteenMinutes();
    }
}
```

Each run fetches every page into one NDJSON snapshot on the staging disk, then
processes that snapshot like a file. `request()` receives the watermark the last
completed run stored, so the next fetch asks only for what changed since.
`nextPage()` returns null to stop paging.

A fetch that fails after its retries is a failed run in `sync:status`, with the
pages that did arrive kept on the staging disk. `timeout()`, `retries()`, and
`retryDelay()` set the HTTP limits and default to 30 seconds, 3 retries, and
1000 ms.

API definitions store their watermark in the `sync_watermarks` table. When an
API definition is registered, `sync:doctor` reports the table if it is missing.

### Syncing from a database

When the data lives in another database, such as an ERP or a legacy system,
generate a database definition:

```bash
php artisan make:sync LegacyOrdersSync --database
```

A database definition names a connection from `config/database.php`, a base
query, and the columns that order its rows uniquely. The model, fields, match
columns, and rules work as in a file sync:

```php
namespace App\Syncs;

use App\Models\Order;
use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Query\Builder;

final class LegacyOrdersSync extends DatabaseSyncDefinition
{
    public function connection(): string
    {
        return 'erp';
    }

    public function query(Builder $query): Builder
    {
        return $query->from('dbo.Orders')
            ->select(['OrderNo', 'Customer', 'Total', 'UpdatedAt']);
    }

    public function keyColumns(): array
    {
        return ['OrderNo'];
    }

    public function watermark(): ?Watermark
    {
        return Watermark::timestamp('UpdatedAt', overlapSeconds: 300);
    }

    public function model(): string
    {
        return Order::class;
    }

    public function fields(): array
    {
        return [
            Field::make('order_no', from: 'OrderNo'),
            Field::make('customer', from: 'Customer'),
            Field::make('total', from: 'Total'),
        ];
    }

    public function matchOn(): array
    {
        return ['order_no'];
    }

    public function schedule(Event $event): void
    {
        $event->everyFifteenMinutes();
    }
}
```

`query()` returns the base query only. The package orders it by
`keyColumns()`, adds the watermark window, and reads it in pages of
`pageSize()` rows, 1000 by default. Each page is one bounded query that starts
after the last key read, so a composite key such as `DocEntry` plus `LineNum`
works. A query that sets its own `limit` or `offset` fails at boot. Every row
becomes one line of an NDJSON snapshot on the staging disk, which the run then
processes like a file.

Pick the watermark by what the source table has:

- **No watermark.** Return null and every run reads the whole query. An
  unchanged table produces the same snapshot, and the ledger skips it. Use this
  for small reference tables.
- **`Watermark::timestamp($column, overlapSeconds: N)`.** A last-modified
  column. Before it reads, the fetch takes `MAX($column)` as the upper bound,
  then reads the rows above the stored bound and up to that one. A row that
  changes during the read gets a later time and lands in the next run. The
  overlap re-reads N seconds below the stored bound, which catches a
  transaction that committed an earlier time after the last read. The upsert on
  `matchOn()` makes the re-read rows harmless. The package stores the value
  exactly as the driver returned it, so a SQL Server `datetime2` keeps all seven
  fractional digits.
- **`Watermark::rowVersion($column)`.** SQL Server only. The upper bound is
  `MIN_ACTIVE_ROWVERSION()`, so a run reads committed rows only and a row
  committed after the read lands in the next run, with no overlap needed. The
  bound moves with every write in the database, so a run over an unchanged table
  is a completed zero-row run.

The watermark moves only when a run lands in `completed`, as for an API
definition. If you change a definition's watermark mode or column, the next
fetch fails and names the `sync_watermarks` row to delete. Deleting it makes the
next run read every row again.

The package only reads from the source. It tracks what it has already synced in
your application's database: the watermark, the upsert on `matchOn()`, and the
checksum ledger. It never flags rows in the source. To mark rows on the source
side, write to it from a `SyncCompleted` listener, as the acknowledgement recipe
below shows for an API.

Before it reads any rows, the fetch checks the source table's columns: every
mapped column, the key columns, and the watermark column and its type. A binary
column in the select fails the run with the column's name, because a snapshot
cannot store raw bytes. Drop the column, or on SQL Server select it as text with
`CONVERT(varchar(max), column, 1)`. A query with joins or unions is checked
against its first page of rows instead. `sync:doctor` runs the same table check
with one schema read per definition, and also reports a connection that is not
configured or a missing PDO extension.

Give the connection a read-only login. On SQL Server, turn on
`READ_COMMITTED_SNAPSHOT` for the database or read from a readable replica, so
the fetch neither blocks writers nor waits on them. Do not add `WITH (NOLOCK)`
to the query: it can return a row twice or skip it while SQL Server splits a
page. A first read of a large table can run longer than the 300 seconds a fetch
holds its lock by default, so raise `lockTimeout()` to keep the scheduler from
starting a second fetch of the same rows.

### Registering definitions at runtime

A definition class in `data-sync.syncs` is registered once. When the sources are
data, such as on a multi-tenant platform where each account connects its own
databases, register them at runtime with a definition provider instead. The
package has no notion of tenants. Your application decides what an account and
a source are, and the provider turns each source into a definition:

```php
namespace App\Syncs;

use App\Models\SourceDatabase;
use ByRcsc\LaravelDataSync\Contracts\DefinitionProvider;

final class AccountSources implements DefinitionProvider
{
    public function definitions(): iterable
    {
        foreach (SourceDatabase::query()->where('active', true)->cursor() as $source) {
            config()->set("database.connections.{$source->connectionName()}", $source->connectionConfig());

            yield new AccountOrdersSync($source);
        }
    }
}
```

```php
final class AccountOrdersSync extends DatabaseSyncDefinition
{
    public function __construct(private readonly SourceDatabase $source) {}

    public function name(): string
    {
        return "orders-{$this->source->account_id}-{$this->source->id}";
    }

    public function connection(): string
    {
        return $this->source->connectionName();
    }

    public function fields(): array
    {
        return [
            Field::make('account_id', from: 'account_id')->default($this->source->account_id),
            Field::make('order_no', from: 'OrderNo'),
            // ...
        ];
    }

    public function matchOn(): array
    {
        return ['account_id', 'order_no'];
    }

    // query(), keyColumns(), model(), and the rest as in any database definition
}
```

List the provider in `config/data-sync.php`, next to or instead of definition
classes:

```php
'syncs' => [
    App\Syncs\AccountSources::class,
],
```

Each definition a provider returns is independent. Its `name()` keys its own
watermark, ledger entries, fetch lock, staging directory, and run history, so
two accounts never share any of them. A name may use only letters, digits,
hyphens, and underscores. Return the same name for the same source on every
call, or the next run starts over with a full read.

The package calls `definitions()` in every process that resolves definitions:
`sync:*` commands, queue workers, and the scheduler. It never calls it while
the application boots, so a provider may read your own tables, and an HTTP
request or a first `migrate` does not touch them. Register each connection
inside `definitions()`, as above, so a queue worker has it too. Your
application stores the credentials. The package only reads the connection
name.

Keep accounts apart in your own tables. Rows from every account land in the
same models, so `matchOn()` must include the account, with a unique index on
those columns. Otherwise one account's upsert overwrites another account's
row. Give every source a read-only login, and check the host an account enters
before you connect to it.

Every definition gets its own scheduled `sync:run`. The scheduler runs due
commands one after another, and a database fetch reads its rows inside that
command, so with many sources call `$event->runInBackground()` in
`schedule()`. `data-sync.overrides` takes provider names like any other, and
`sync:doctor` checks every provider definition.

## What is included

- Class-based sync definitions with a declarative field mapping, per-row
  validation rules, and configurable write, duplicate, and unknown-column
  policies.
- API definitions that page through an HTTP endpoint into an NDJSON snapshot,
  with a watermark that resumes each fetch where the last completed run ended.
- Database definitions that read any configured connection in key-ordered
  pages, with a timestamp watermark, or a `rowversion` watermark on SQL Server.
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
- An API or database definition's watermark advances only when a run lands in
  `completed`. A failed or partially failed run leaves it alone, so the next
  fetch reads the same window again. A fetch that returns no records is still a
  completed run with zero rows, so a quiet source shows up in `sync:status` and
  its watermark advances. Dry runs never advance it.
- One row that fails on every run holds the watermark in place, and each run
  then reads a growing window. When a definition's last 5 runs all ended without
  `completed`, `sync:status` and `sync:doctor` warn about it. Set the count with
  `data-sync.stalled_watermark_runs`.

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
  or a source database is the host application's job; see the acknowledgement
  recipe above.
- **Hard deletes in a source database.** A watermark only sees rows that still
  exist, so a row deleted at the source is never read again.
- **The package's own tables on SQL Server.** A database definition can read
  from SQL Server, but the package's ledger and history tables are tested on
  SQLite, MySQL, and PostgreSQL only.
- **Cross-connection joins.** A definition's query runs on one connection.
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

- Upgrading within `2.x` is safe. Nothing you use will break.
- Only a new major version, like `3.0.0`, can break your code. Each one lists
  its upgrade steps in [CHANGELOG.md](CHANGELOG.md).
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
