# Workbench demo app

A real, bootable Laravel application that installs `byrcsc/laravel-data-sync` the way an
application would. It exists so the package can be driven by hand — every README example is
executed here before it is published, and it is the first place the queued path meets an
actual `queue:work` worker rather than a synchronous batch.

It is a development tool. It is excluded from the Composer dist archive via `.gitattributes`
and is not covered by CI, so it may drift.

## Setup

```bash
composer build
```

That runs `vendor/bin/testbench workbench:build`, which creates the SQLite database, publishes
the package migration exactly as `vendor:publish --tag=data-sync-migrations` would in a real
app, migrates, and seeds five placeholder products.

Testbench runs the seeder off the `DatabaseRefreshed` event, so `migrate:fresh` seeds too. Bare
`migrate --seed` does not — it looks for `Database\Seeders\DatabaseSeeder`, and the seeder here
lives under the Workbench namespace. Name it if you want that route:

```bash
vendor/bin/testbench migrate --seed --seeder="Workbench\Database\Seeders\DatabaseSeeder"
```

## The demo loop

```bash
vendor/bin/testbench queue:work          # terminal 1
vendor/bin/testbench sync:run products   # terminal 2 — queues a batch
vendor/bin/testbench sync:status         # counters and batch progress
```

To skip the queue and process the file in the command itself:

```bash
vendor/bin/testbench sync:run products --now
```

Either way the first run reports **6 created, 2 updated** — `DS-1001` and `DS-1002` are seeded
placeholders that the CSV overwrites. `ProductsSync` uses `chunkSize(2)`, so the eight rows fan
out into four queued jobs and `sync:status` shows real batch progress.

Run it a second time and nothing happens: the checksum is already in the `sync_files` ledger.
Use `--force` to reprocess, or edit the CSV so its checksum changes.

Other things worth trying:

```bash
vendor/bin/testbench sync:run products --dry-run
vendor/bin/testbench sync:status --failures
vendor/bin/testbench sync:doctor
vendor/bin/testbench make:sync Orders
```

`sync:doctor` reports one warning by design — the staging disk is local, which it flags because
multi-server workers need shared staging. On a single-machine demo that is expected.

## Layout

| Path | What it is |
|------|------------|
| `.env` | The demo app's environment. Testbench copies it into the skeleton, where the package test suite also boots — read the comments in it before changing a value |
| `app/Providers/WorkbenchServiceProvider.php` | Stands in for an app's `config/*.php` edits: registers the sync, the `incoming` and `sync-work` disks, and the file-backed SQLite connection |
| `app/Syncs/ProductsSync.php` | The demo definition |
| `app/Models/Product.php` | The destination model |
| `database/migrations/` | The `products` table. `jobs`, `job_batches`, `failed_jobs`, `users` and `cache` come from Testbench's default migrations; the data-sync tables are published by `composer build` |
| `storage/app/incoming/products/` | The supplier drop directory the sync reads |
| `storage/app/data-sync/` | Staging and archive working directories (gitignored) |

`make:sync` writes into the running application, which under Testbench is the skeleton inside
`vendor/orchestra/testbench-core/laravel/app/Syncs/`, with the skeleton's `App\` namespace.
`composer clear` removes it again.

## Resetting

```bash
composer clear   # purges the skeleton and deletes the demo database
composer build   # rebuild from scratch
```

## Why the demo pins three SQLite pragmas

The demo runs on a file-backed SQLite database, which is what Testbench packages normally do.
It is not a coverage gap — CI runs the real suite against MySQL 8.4 and PostgreSQL 17 via the
`DB_DRIVER` matrix in `.github/workflows/run-tests.yml`, and the package writes rows through
Laravel's portable `upsert()` / `insertOrIgnore()`, so there is no driver-specific code here.

What SQLite does change is concurrency: it takes one writer at a time. Running `sync:run`
against several `queue:work` processes on Testbench's stock settings produces
`SQLSTATE[HY000]: General error: 5 database is locked`, and it fails misleadingly — the import
itself still finishes, but `ProcessFileJob` lands in `failed_jobs`, which reads like a package
bug when it is not. `WorkbenchServiceProvider` therefore sets three pragmas on the connection:

| Pragma | Why |
|--------|-----|
| `journal_mode=wal` | Lets readers through while a writer holds the lock |
| `busy_timeout=5000` | Makes competing writers wait their turn instead of failing outright |
| `transaction_mode=IMMEDIATE` | Takes the write lock up front, so the timeout actually applies |

The last one is load-bearing. Testbench's default DEFERRED transactions take a read lock first
and upgrade to a write lock on the first write; if another connection committed in between,
SQLite returns BUSY straight away and never honours `busy_timeout`. WAL and the timeout on
their own did not fix it — adding `IMMEDIATE` did.

Verified clean with four concurrent workers, over three consecutive runs. The single-worker
loop documented above was never affected. If you are pointing a real application at SQLite and
running queued syncs, you want the same three settings.
