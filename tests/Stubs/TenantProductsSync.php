<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\DatabaseSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\Watermark;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Query\Builder;

/**
 * One account's source database, as a host application would write it.
 */
final class TenantProductsSync extends DatabaseSyncDefinition
{
    public function __construct(
        private readonly string $account,
        private readonly string $connection,
        private readonly string $table,
    ) {}

    public function name(): string
    {
        return "tenant-products-{$this->account}";
    }

    public function connection(): string
    {
        return $this->connection;
    }

    public function query(Builder $query): Builder
    {
        return $query->from($this->table)->select(['SKU', 'NAME', 'PRICE', 'UPDATED_AT']);
    }

    public function keyColumns(): array
    {
        return ['SKU'];
    }

    public function watermark(): Watermark
    {
        return Watermark::timestamp('UPDATED_AT');
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            // The source has no account column, so every row is stamped with
            // the account this definition reads for.
            Field::make('category', from: 'ACCOUNT')->default($this->account),
            Field::make('sku', from: 'SKU'),
            Field::make('name', from: 'NAME'),
        ];
    }

    /**
     * Two accounts can use the same SKU, so the account is part of the match.
     */
    public function matchOn(): array
    {
        return ['category', 'sku'];
    }

    public function schedule(Event $event): void
    {
        $event->everyFiveMinutes();
    }
}
