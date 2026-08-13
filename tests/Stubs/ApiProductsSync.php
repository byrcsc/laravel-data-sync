<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class ApiProductsSync extends ApiProductSyncDefinition
{
    public function name(): string
    {
        return 'api-products';
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
}
