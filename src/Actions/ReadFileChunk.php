<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final readonly class ReadFileChunk
{
    public function __construct(
        private FilesystemManager $filesystems,
        private MapRow $mapRow,
    ) {}

    /**
     * @param  list<string|int>  $unknown
     * @param  array<int, true>  $selectedRows
     * @return list<array{
     *     row_number: int,
     *     raw: array<string|int, string|null>,
     *     mapped: array<string, mixed>|null,
     *     exception: Throwable|null,
     *     duplicate: bool
     * }>
     */
    public function __invoke(
        SyncDefinition $definition,
        SyncFile $file,
        array $unknown,
        array $selectedRows,
        int $offset,
        int $limit,
    ): array {
        $configured = config('data-sync.staging.disk', 'local');
        $disk = is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'local';
        $staging = $this->filesystems->disk($disk);
        $path = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $stream = $staging->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        $rows = [];
        $position = 0;

        try {
            foreach ($definition->format()->rows($stream) as $rowNumber => $raw) {
                if ($position++ < $offset) {
                    continue;
                }

                if (count($rows) >= $limit) {
                    break;
                }

                $mapped = null;
                $exception = null;

                try {
                    $mapped = ($this->mapRow)($definition, $raw, $unknown);
                    $mapped = $definition->prepare($mapped);
                } catch (Throwable $mappingException) {
                    $exception = $mappingException;
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'raw' => $raw,
                    'mapped' => $mapped,
                    'exception' => $exception,
                    'duplicate' => ! isset($selectedRows[$rowNumber]),
                ];
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }
}
