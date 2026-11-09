<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Support\Checksum;
use ByRcsc\LaravelDataSync\Support\Filename;
use ByRcsc\LaravelDataSync\Support\StagingArea;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final class StageFile
{
    public function __construct(
        private readonly Checksum $checksum,
    ) {}

    public function __invoke(
        Filesystem $source,
        Filesystem $staging,
        string $sourcePath,
        string $definition,
        string $filename,
    ): string {
        $temporaryPath = $this->temporaryPath($definition, $filename);
        $sourceStream = $source->readStream($sourcePath);

        if (! is_resource($sourceStream)) {
            throw new RuntimeException("Unable to open source file [{$sourcePath}] for staging.");
        }

        try {
            if (! $staging->writeStream($temporaryPath, $sourceStream)) {
                throw new RuntimeException("Unable to stage source file [{$sourcePath}] at [{$temporaryPath}].");
            }
        } finally {
            fclose($sourceStream);
        }

        return $this->finalize($staging, $temporaryPath, $definition, $filename);
    }

    /**
     * Names a scratch path a caller can write to before the contents — and so
     * the checksum the final path is named for — are known.
     */
    public function temporaryPath(string $definition, string $filename): string
    {
        $directory = StagingArea::directoryFor($definition);
        $token = bin2hex(random_bytes(8));

        return "{$directory}/.staging-{$token}-".Filename::safe($filename);
    }

    /**
     * Checksums a staged scratch file and moves it to the path the rest of the
     * pipeline reads from.
     */
    public function finalize(
        Filesystem $staging,
        string $temporaryPath,
        string $definition,
        string $filename,
    ): string {
        $checksum = $this->checksum->for($staging, $temporaryPath);
        $finalPath = StagingArea::pathFor($definition, $checksum, $filename);

        if ($staging->exists($finalPath)) {
            $staging->delete($finalPath);
        }

        if (! $staging->move($temporaryPath, $finalPath)) {
            $staging->delete($temporaryPath);

            throw new RuntimeException("Unable to finalize staged file [{$finalPath}].");
        }

        return $checksum;
    }
}
