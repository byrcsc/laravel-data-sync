<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Field;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

abstract class ApiProductSyncDefinition extends ApiSyncDefinition
{
    public function endpoint(): string
    {
        return 'https://middleware.test/api/products';
    }

    public function request(PendingRequest $http, mixed $watermark): PendingRequest
    {
        return $http->withQueryParameters([
            'updated_since' => is_string($watermark) ? $watermark : '1970-01-01',
        ]);
    }

    public function nextPage(Response $response): ?string
    {
        $next = $response->json('meta.next_page_url');

        return is_string($next) ? $next : null;
    }

    public function records(Response $response): iterable
    {
        $records = $response->json('data');

        return is_array($records) ? $records : [];
    }

    public function watermarkFrom(Response $response, iterable $records): mixed
    {
        return $response->json('meta.max_updated_at');
    }

    public function model(): string
    {
        return Product::class;
    }

    public function fields(): array
    {
        return [
            Field::make('sku', from: 'SKU'),
            Field::make('name', from: 'NAME'),
            Field::make('price', from: 'PRICE')
                ->transform(fn (?string $price): int => (int) round(((float) $price) * 100)),
            Field::make('category', from: 'CATEGORY')->optional()->default('general'),
        ];
    }

    public function matchOn(): array
    {
        return ['sku'];
    }

    public function rules(): array
    {
        return [
            'sku' => ['required'],
            'name' => ['required'],
            'price' => ['integer', 'min:0'],
        ];
    }
}
