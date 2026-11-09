<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Data\FilePlan;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\DuplicatePolicy;
use ByRcsc\LaravelDataSync\Exceptions\DuplicateRowException;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Support\ChunkSize;
use ByRcsc\LaravelDataSync\Support\RowIdentity;
use JsonException;

/**
 * Reads a staged payload once before any row is written: checks the header,
 * resolves duplicate identities under the definition's DuplicatePolicy, and
 * splits the rows into chunks.
 */
final readonly class PlanFile
{
    public function __construct(
        private ReadStagedFile $readStagedFile,
        private MapRow $mapRow,
    ) {}

    /**
     * A dry run passes $prepare as false, so the host's prepare() hook never
     * runs for rows that will not be written.
     */
    public function __invoke(SyncDefinition $definition, SyncFile $file, bool $prepare = true): FilePlan
    {
        $unmappedColumns = $this->mapRow->validateHeader(
            $definition,
            $this->readStagedFile->header($definition, $file),
        );
        $keptRowByIdentity = [];
        $rowsTotal = 0;

        foreach ($this->readStagedFile->rows($definition, $file) as $rowNumber => $raw) {
            $rowsTotal++;
            $row = $this->mapRow->toChunkRow($definition, $rowNumber, $raw, $unmappedColumns, prepare: $prepare);
            $identity = $this->identity($definition, $row->mapped);

            if ($identity === null) {
                // A row that fails to map has no identity to compare, so it is
                // kept and later recorded as a row failure.
                $keptRowByIdentity["mapping-error-{$rowNumber}"] = $rowNumber;

                continue;
            }

            if (! array_key_exists($identity, $keptRowByIdentity)) {
                $keptRowByIdentity[$identity] = $rowNumber;

                continue;
            }

            if ($definition->onDuplicate() === DuplicatePolicy::Fail) {
                throw DuplicateRowException::identity(
                    $definition::class,
                    $identity,
                    $keptRowByIdentity[$identity],
                    $rowNumber,
                );
            }

            if ($definition->onDuplicate() === DuplicatePolicy::LastWins) {
                $keptRowByIdentity[$identity] = $rowNumber;
            }
        }

        return new FilePlan(
            unmappedColumns: $unmappedColumns,
            keptRows: array_fill_keys(array_values($keptRowByIdentity), true),
            chunks: $this->chunks($definition, $rowsTotal),
            rowsTotal: $rowsTotal,
        );
    }

    /**
     * @param  array<string, mixed>|null  $mapped
     */
    private function identity(SyncDefinition $definition, ?array $mapped): ?string
    {
        if ($mapped === null) {
            return null;
        }

        try {
            return RowIdentity::key($definition->matchOn(), $mapped);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return list<array{offset: int, limit: int}>
     */
    private function chunks(SyncDefinition $definition, int $rowsTotal): array
    {
        $size = ChunkSize::for($definition);
        $chunks = [];

        for ($offset = 0; $offset < $rowsTotal; $offset += $size) {
            $chunks[] = [
                'offset' => $offset,
                'limit' => min($size, $rowsTotal - $offset),
            ];
        }

        return $chunks;
    }
}
