<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Generator;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

/**
 * Reads a staged payload through the definition's format, opening a fresh
 * stream for each pass.
 */
final readonly class ReadStagedFile
{
    public function __construct(
        private FilesystemManager $filesystems,
    ) {}

    /**
     * @return list<string|int>
     */
    public function header(SyncDefinition $definition, SyncFile $file): array
    {
        $stream = $this->open($definition, $file);

        try {
            return $definition->format()->header($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return Generator<int, array<string|int, string|null>>
     */
    public function rows(SyncDefinition $definition, SyncFile $file): Generator
    {
        $stream = $this->open($definition, $file);

        try {
            yield from $definition->format()->rows($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function open(SyncDefinition $definition, SyncFile $file): mixed
    {
        $path = StagingArea::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $stream = $this->filesystems->disk(StagingArea::disk())->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        return $stream;
    }
}
