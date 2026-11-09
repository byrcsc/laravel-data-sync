<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredMethods(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
            continue;
        }

        $names[] = $method->getName();
    }

    sort($names);

    return $names;
}

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredProperties(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $names[] = $property->getName();
    }

    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function classesIn(string $directory): array
{
    $files = glob(__DIR__.'/../src/'.$directory.'/*.php');
    $names = array_map(
        fn (string $file): string => basename($file, '.php'),
        $files === false ? [] : $files,
    );
    sort($names);

    return $names;
}

function sourceDriver(): string
{
    $driver = env('DB_SOURCE_DRIVER', env('DB_DRIVER', 'sqlite'));

    return is_string($driver) ? $driver : 'sqlite';
}

/**
 * The table the database-source stubs read. It lives on the `source`
 * connection, which shares the package's database on mysql and pgsql and is a
 * database of its own on sqlite and sqlsrv.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function createSourceProducts(array $rows = []): void
{
    $schema = Schema::connection('source');
    $schema->dropIfExists('source_products');
    $schema->create('source_products', function (Blueprint $table): void {
        $table->string('SKU')->primary();
        $table->string('NAME')->nullable();
        $table->string('PRICE')->nullable();
        $table->text('META')->nullable();
        $table->dateTime('UPDATED_AT')->nullable();
    });

    seedSourceProducts($rows);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function seedSourceProducts(array $rows): void
{
    $table = DB::connection('source')->table('source_products');
    $table->delete();

    if ($rows !== []) {
        $table->insert($rows);
    }
}
