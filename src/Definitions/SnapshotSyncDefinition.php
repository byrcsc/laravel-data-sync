<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Support\StagingArea;

/**
 * A definition whose payload the package fetches and writes itself, as one
 * NDJSON snapshot in the staging area, before the file pipeline processes it.
 */
abstract class SnapshotSyncDefinition extends SyncDefinition
{
    /**
     * Seconds the fetch holds its per-definition lock. A scheduled fetch that
     * starts while the lock is held is a recorded no-op, so set this above the
     * longest fetch you expect, such as a first full read of a large source.
     */
    public function lockTimeout(): int
    {
        return 300;
    }

    /**
     * The package writes the snapshot, so its format is not a subclass concern.
     */
    final public function format(): Reader
    {
        return Format::ndjson();
    }

    /**
     * Snapshots are written straight into the staging area, so a snapshot
     * definition sources from its own staging directory rather than a
     * host-configured disk.
     */
    final public function source(): Source
    {
        return Source::disk(StagingArea::disk())
            ->path(StagingArea::directoryFor($this->name()));
    }
}
