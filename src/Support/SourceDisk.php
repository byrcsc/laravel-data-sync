<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;

final class SourceDisk
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
    ) {}

    public function for(Source $source): Filesystem
    {
        $disk = $source->diskName();

        if ($disk !== null) {
            return $this->filesystems->disk($disk);
        }

        $connection = $source->connectionName();

        if ($connection === null) {
            throw InvalidConfigurationException::invalidConnection('');
        }

        $connections = config('data-sync.connections', []);
        $config = is_array($connections) ? ($connections[$connection] ?? null) : null;

        if (! is_array($config)) {
            throw InvalidConfigurationException::unknownConnection($connection);
        }

        return $this->filesystems->build($config);
    }
}
