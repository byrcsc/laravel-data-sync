<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Support\Checksum;
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

        $checksum = $this->checksum->for($staging, $temporaryPath);
        $finalPath = self::pathFor($definition, $checksum, $filename);

        if ($staging->exists($finalPath)) {
            $staging->delete($finalPath);
        }

        if (! $staging->move($temporaryPath, $finalPath)) {
            $staging->delete($temporaryPath);

            throw new RuntimeException("Unable to finalize staged file [{$finalPath}].");
        }

        return $checksum;
    }

    public static function pathFor(string $definition, string $checksum, string $filename): string
    {
        $root = self::stagingRoot();
        $safeFilename = str_replace(['/', '\\'], '-', $filename);

        return "{$root}/{$definition}/{$checksum}-{$safeFilename}";
    }

    private function temporaryPath(string $definition, string $filename): string
    {
        $root = self::stagingRoot();
        $safeFilename = str_replace(['/', '\\'], '-', $filename);
        $token = bin2hex(random_bytes(8));

        return "{$root}/{$definition}/.staging-{$token}-{$safeFilename}";
    }

    private static function stagingRoot(): string
    {
        $configured = config('data-sync.staging.path', 'data-sync/staging');

        if (! is_string($configured) || trim($configured, '/') === '') {
            return 'data-sync/staging';
        }

        return trim($configured, '/');
    }
}
