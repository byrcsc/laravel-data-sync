<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\ChunkRow;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Models\SyncFile;

final readonly class ReadFileChunk
{
    public function __construct(
        private ReadStagedFile $readStagedFile,
        private MapRow $mapRow,
    ) {}

    /**
     * @param  list<string|int>  $unmappedColumns
     * @param  array<int, true>  $keptRows
     * @return list<ChunkRow>
     */
    public function __invoke(
        SyncDefinition $definition,
        SyncFile $file,
        array $unmappedColumns,
        array $keptRows,
        int $offset,
        int $limit,
    ): array {
        $rows = [];
        $position = 0;

        foreach ($this->readStagedFile->rows($definition, $file) as $rowNumber => $raw) {
            if ($position++ < $offset) {
                continue;
            }

            if (count($rows) >= $limit) {
                break;
            }

            $rows[] = $this->mapRow->toChunkRow(
                $definition,
                $rowNumber,
                $raw,
                $unmappedColumns,
                duplicate: ! isset($keptRows[$rowNumber]),
            );
        }

        return $rows;
    }
}
