<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Contracts\Reader as ReaderContract;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

abstract class ApiSyncDefinition extends SyncDefinition
{
    abstract public function endpoint(): string;

    /**
     * Authentication, headers, and query parameters belong to the host application.
     * The package owns the paging loop and the snapshot.
     */
    public function request(PendingRequest $http, mixed $watermark): PendingRequest
    {
        return $http;
    }

    /**
     * The absolute URL of the next page, or null to stop paging.
     */
    public function nextPage(Response $response): ?string
    {
        return null;
    }

    /**
     * @return iterable<array-key, mixed>
     */
    public function records(Response $response): iterable
    {
        $records = $response->json();

        return is_array($records) ? $records : [];
    }

    /**
     * The value the next fetch should resume from. Committed only when the run
     * lands in the completed status.
     *
     * @param  iterable<array-key, mixed>  $records
     */
    public function watermarkFrom(Response $response, iterable $records): mixed
    {
        return null;
    }

    /**
     * Seconds to wait for a single HTTP response.
     */
    public function timeout(): int
    {
        return 30;
    }

    /**
     * Extra attempts after the first one. Zero disables retrying.
     */
    public function retries(): int
    {
        return 3;
    }

    /**
     * Milliseconds to wait between attempts.
     */
    public function retryDelay(): int
    {
        return 1000;
    }

    /**
     * The package writes the snapshot, so its format is not a subclass concern.
     */
    final public function format(): ReaderContract
    {
        return Reader::ndjson();
    }

    /**
     * Snapshots are written straight into the staging area, so an API definition
     * sources from its own staging directory rather than a host-configured disk.
     */
    final public function source(): Source
    {
        return Source::disk(StagingArea::disk())
            ->path(StagingArea::directoryFor($this->name()));
    }
}
