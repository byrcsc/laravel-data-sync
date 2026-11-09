<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\DiscoveryResult;
use ByRcsc\LaravelDataSync\Definitions\ApiSyncDefinition;
use ByRcsc\LaravelDataSync\Exceptions\ApiFetchException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pages a remote API and hands each record to the shared snapshot action,
 * which stages them as one NDJSON snapshot for the file pipeline.
 */
final readonly class FetchApiSnapshot
{
    public function __construct(
        private HttpFactory $http,
        private StageSnapshot $stageSnapshot,
    ) {}

    public function __invoke(ApiSyncDefinition $definition, bool $force = false): DiscoveryResult
    {
        return ($this->stageSnapshot)(
            $definition,
            fn (mixed $watermark): Generator => $this->page($definition, $watermark),
            fn (Throwable $exception): Throwable => ApiFetchException::fetchFailed(
                $definition->name(),
                $definition->endpoint(),
                $exception,
            ),
            $force,
        );
    }

    /**
     * Walks every page, yielding each record as it arrives, and returns the last
     * non-null watermark a page reported.
     *
     * @return Generator<int, mixed, mixed, mixed>
     */
    private function page(ApiSyncDefinition $definition, mixed $watermark): Generator
    {
        $request = $definition->request($this->client($definition), $watermark);
        $url = $definition->endpoint();
        $pending = $watermark;
        $visited = [];

        while ($url !== null) {
            if (isset($visited[$url])) {
                throw ApiFetchException::pagingLoop($definition->name(), $url);
            }

            $visited[$url] = true;
            $response = $this->send($request, $url);
            $records = iterator_to_array($definition->records($response), preserve_keys: false);

            yield from $records;

            // Each page sees only its own records, so a page with nothing to
            // report, such as an empty last page, keeps the value found so far.
            $pending = $definition->watermarkFrom($response, $records) ?? $pending;
            $url = $definition->nextPage($response);
        }

        return $pending;
    }

    private function send(PendingRequest $request, string $url): Response
    {
        // Guzzle's query option replaces whatever query string the URL carries,
        // so a next-page link's own parameters have to be handed to the request
        // instead of left on the URL. withOptions() lets them win over the ones
        // the definition set, which is what a next-page link is for.
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (clone $request)
            ->withOptions(['query' => $query])
            ->get(Str::before($url, '?'))
            // A single-attempt request is the one case Laravel's retry helper
            // leaves unthrown.
            ->throw();
    }

    private function client(ApiSyncDefinition $definition): PendingRequest
    {
        return $this->http
            ->timeout($definition->timeout())
            ->retry(
                // Laravel counts the first attempt and retries() counts only the
                // extra ones, so the two differ by one.
                $definition->retries() + 1,
                $definition->retryDelay(),
                // A rejected request stays rejected however often it is sent;
                // only a transport fault or a server fault is worth repeating.
                fn (Throwable $exception): bool => $this->isRetryable($exception),
            );
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status >= 500 || $status === 429;
    }
}
