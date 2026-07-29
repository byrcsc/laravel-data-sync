<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Data\FilePlan;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final readonly class PlanFile
{
    public function __construct(
        private FilesystemManager $filesystems,
        private MapRow $mapRow,
    ) {}

    public function __invoke(SyncDefinition $definition, SyncFile $file): FilePlan
    {
        $reader = $definition->format();
        $staging = $this->stagingDisk();
        $path = StageFile::pathFor(
            $definition->name(),
            (string) $file->checksum,
            (string) $file->filename,
        );
        $header = $this->header($reader, $staging, $path);
        $unknown = $this->mapRow->validateSchema($definition, $header);
        $selected = [];
        $rowsTotal = 0;

        foreach ($this->rows($reader, $staging, $path) as $rowNumber => $raw) {
            $rowsTotal++;

            try {
                $mapped = ($this->mapRow)($definition, $raw, $unknown);
                $mapped = $definition->prepare($mapped);
                $identity = $this->identity($definition, $mapped);
            } catch (Throwable) {
                $selected["mapping-error-{$rowNumber}"] = $rowNumber;

                continue;
            }

            if (! array_key_exists($identity, $selected)) {
                $selected[$identity] = $rowNumber;

                continue;
            }

            $firstRow = $selected[$identity];

            if ($definition->onDuplicate() === DuplicatePolicy::Fail) {
                throw DuplicateRowException::identity(
                    $definition::class,
                    $identity,
                    $firstRow,
                    $rowNumber,
                );
            }

            if ($definition->onDuplicate() === DuplicatePolicy::LastWins) {
                $selected[$identity] = $rowNumber;
            }
        }

        return new FilePlan(
            unmappedColumns: $unknown,
            selectedRows: array_fill_keys(array_values($selected), true),
            chunks: $this->chunks($definition, $rowsTotal),
            rowsTotal: $rowsTotal,
        );
    }

    /**
     * @return list<array{offset: int, limit: int}>
     */
    private function chunks(SyncDefinition $definition, int $rowsTotal): array
    {
        $configured = config("data-sync.overrides.{$definition->name()}.chunk_size");
        $size = is_int($configured) && $configured > 0
            ? $configured
            : $definition->chunkSize();
        $chunks = [];

        for ($offset = 0; $offset < $rowsTotal; $offset += $size) {
            $chunks[] = [
                'offset' => $offset,
                'limit' => min($size, $rowsTotal - $offset),
            ];
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function identity(SyncDefinition $definition, array $row): string
    {
        $identity = [];

        foreach ($definition->matchOn() as $attribute) {
            $identity[$attribute] = $row[$attribute] ?? null;
        }

        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string|int>
     */
    private function header(Reader $reader, Filesystem $staging, string $path): array
    {
        $stream = $staging->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        try {
            return $reader->header($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return Generator<int, array<string|int, string|null>>
     */
    private function rows(Reader $reader, Filesystem $staging, string $path): Generator
    {
        $stream = $staging->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read staged file [{$path}].");
        }

        try {
            yield from $reader->rows($stream);
        } finally {
            fclose($stream);
        }
    }

    private function stagingDisk(): Filesystem
    {
        $configured = config('data-sync.staging.disk', 'local');
        $disk = is_string($configured) && trim($configured) !== ''
            ? $configured
            : 'local';

        return $this->filesystems->disk($disk);
    }
}
