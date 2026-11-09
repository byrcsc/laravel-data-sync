<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Actions\InspectInstallation;
use ByRcsc\LaravelDataSync\Actions\PruneHistory;
use ByRcsc\LaravelDataSync\Contracts\Reader;
use ByRcsc\LaravelDataSync\Data\DoctorReport;
use ByRcsc\LaravelDataSync\Data\PruneReport;
use ByRcsc\LaravelDataSync\Definitions\Definition;
use ByRcsc\LaravelDataSync\Definitions\Destination;
use ByRcsc\LaravelDataSync\Definitions\Field;
use ByRcsc\LaravelDataSync\Definitions\FilenamePattern;
use ByRcsc\LaravelDataSync\Definitions\Format;
use ByRcsc\LaravelDataSync\Definitions\SnapshotSyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\Source;
use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Definitions\TransferDefinition;
use ByRcsc\LaravelDataSync\Events\SyncEvent;
use ByRcsc\LaravelDataSync\Facades\DataSync;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRowFailure;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use ByRcsc\LaravelDataSync\SyncManager;

/**
 * The released public surface, pinned member by member.
 *
 * These assertions are exact rather than subset checks. A new public member is a
 * deliberate addition to the 2.x API that cannot be taken back before 3.0, so
 * adding one should require adding it here too.
 */
it('pins the manager command and query surface', function (): void {
    expect(declaredMethods(SyncManager::class))->toBe([
        'definition',
        'enabled',
        'queue',
        'registered',
        'run',
    ]);
});

it('documents every manager method on the facade and nothing else', function (): void {
    $docblock = (string) (new ReflectionClass(DataSync::class))->getDocComment();

    preg_match_all('/@method static [^\n]*?\b(\w+)\(/', $docblock, $matches);
    $documented = $matches[1];
    sort($documented);

    expect(array_values(array_unique($documented)))->toBe(declaredMethods(SyncManager::class));
});

it('pins the definition base class to naming, routing, and discovery', function (): void {
    expect(declaredMethods(Definition::class))->toBe([
        'name',
        'queue',
        'queueConnection',
        'schedule',
        'source',
    ]);
});

it('pins the sync definition seams a host application overrides', function (): void {
    expect(declaredMethods(SyncDefinition::class))->toBe([
        'afterSave',
        'atomic',
        'beforeSave',
        'captureFailedPayloads',
        'captureUnmappedInto',
        'chunkSize',
        'fields',
        'format',
        'matchOn',
        'maxErrors',
        'model',
        'onDuplicate',
        'onUnknownColumns',
        'prepare',
        'rules',
        'withValidator',
        'withoutFailedPayloadCapture',
        'writeMode',
    ]);
});

it('pins the snapshot definition seams API and database definitions share', function (): void {
    expect(declaredMethods(SnapshotSyncDefinition::class))->toBe([
        'format',
        'lockTimeout',
        'source',
    ]);
});

it('pins the transfer definition seams', function (): void {
    expect(declaredMethods(TransferDefinition::class))->toBe([
        'afterTransfer',
        'destination',
        'fields',
        'filenamePattern',
        'matchOn',
        'model',
        'onUnmatched',
    ]);
});

it('pins the declarative definition DSL', function (): void {
    expect(declaredMethods(Field::class))->toBe([
        'apply',
        'default',
        'defaultValue',
        'from',
        'hasDefault',
        'isOptional',
        'make',
        'name',
        'optional',
        'transform',
    ]);

    expect(declaredMethods(Source::class))->toBe([
        'connection',
        'connectionName',
        'copyPath',
        'copyTo',
        'deleteOriginal',
        'deletesOriginal',
        'disk',
        'diskName',
        'minFileAge',
        'minimumAgeSeconds',
        'movePath',
        'moveTo',
        'path',
        'pathName',
    ]);

    expect(declaredMethods(Destination::class))->toBe([
        'disk',
        'diskName',
        'existingPolicy',
        'onExisting',
        'path',
        'pathTemplate',
    ]);

    expect(declaredMethods(FilenamePattern::class))->toBe(['match']);
});

it('pins the format factory and the contract every reader satisfies', function (): void {
    expect(declaredMethods(Format::class))->toBe([
        'csv',
        'delimited',
        'fixedWidth',
        'json',
        'ndjson',
        'xlsx',
    ]);

    expect(declaredMethods(Reader::class))->toBe(['header', 'rows']);

    foreach (classesIn('Readers') as $reader) {
        $class = 'ByRcsc\\LaravelDataSync\\Readers\\'.$reader;

        if ($reader === 'ReaderValue') {
            continue;
        }

        expect(is_a($class, Reader::class, true))
            ->toBeTrue("[{$reader}] must satisfy the Reader contract.");
    }
});

it('pins the public enums and their cases', function (): void {
    $cases = [];

    foreach (classesIn('Enums') as $enum) {
        $class = 'ByRcsc\\LaravelDataSync\\Enums\\'.$enum;
        $cases[$enum] = array_map(
            fn (ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
            (new ReflectionEnum($class))->getCases(),
        );
    }

    expect($cases)->toBe([
        'DuplicatePolicy' => ['last_wins', 'first_wins', 'fail'],
        'ExistingFilePolicy' => ['overwrite', 'fail'],
        'RowOutcome' => ['created', 'updated', 'skipped'],
        'RunStatus' => ['pending', 'running', 'completed', 'completed_with_errors', 'failed', 'cancelled'],
        'SyncFileStatus' => ['pending', 'processing', 'completed', 'failed'],
        'UnknownColumnPolicy' => ['strict', 'ignore', 'capture'],
        'UnmatchedFilenamePolicy' => ['skip', 'fail'],
        'WriteMode' => ['upsert', 'create_only', 'update_only'],
        'WriteStrategy' => ['bulk', 'eloquent'],
    ]);
});

it('pins the public events and the facts each one carries', function (): void {
    $events = [];

    foreach (classesIn('Events') as $event) {
        $class = 'ByRcsc\\LaravelDataSync\\Events\\'.$event;
        $events[$event] = declaredProperties($class);
    }

    expect($events)->toBe([
        'SyncCompleted' => ['run'],
        'SyncEvent' => ['run'],
        'SyncFailed' => ['run'],
        'SyncStarted' => ['run'],
    ]);

    foreach (classesIn('Events') as $event) {
        $class = 'ByRcsc\\LaravelDataSync\\Events\\'.$event;

        expect(is_a($class, SyncEvent::class, true))->toBeTrue("[{$event}] must be a SyncEvent.");
    }
});

it('pins the public value objects and their readable members', function (): void {
    $data = [];

    foreach (classesIn('Data') as $object) {
        $class = 'ByRcsc\\LaravelDataSync\\Data\\'.$object;
        $data[$object] = declaredProperties($class);
    }

    expect($data)->toBe([
        'ChunkResult' => ['created', 'failed', 'skipped', 'total', 'updated'],
        'ChunkRow' => ['duplicate', 'exception', 'mapped', 'raw', 'rowNumber'],
        'DiscoveryResult' => ['deferred', 'files', 'locked', 'skipped'],
        'DoctorReport' => ['failures', 'warnings'],
        'FilePlan' => ['chunks', 'keptRows', 'rowsTotal', 'unmappedColumns'],
        'PruneReport' => ['archives', 'failedFiles', 'rowFailures', 'runs'],
        'RunReport' => ['deferred', 'definition', 'discovered', 'locked', 'runs', 'skipped'],
        'RunResult' => ['created', 'failed', 'run', 'skipped', 'status', 'total', 'updated', 'writeStrategy'],
        'TransferredFile' => [
            'checksum', 'destinationDisk', 'destinationPath', 'file', 'run', 'segments', 'skipped',
        ],
    ]);
});

it('pins the two actions the documentation tells a host application to invoke', function (): void {
    // Actions are internal by default. These two are the exception, because the
    // health check and retention guides document them for a /health endpoint and
    // a custom pruning schedule.
    $invokable = [
        InspectInstallation::class => DoctorReport::class,
        PruneHistory::class => PruneReport::class,
    ];

    foreach ($invokable as $action => $report) {
        $invoke = new ReflectionMethod($action, '__invoke');

        expect($invoke->isPublic())->toBeTrue("[{$action}] must stay invokable.")
            ->and($invoke->getNumberOfRequiredParameters())
            ->toBe(0, "[{$action}] must stay callable with no arguments.")
            ->and((string) $invoke->getReturnType())
            ->toBe($report, "[{$action}] must keep returning [{$report}].")
            ->and(declaredMethods($action))
            ->toBe([], "[{$action}] must expose nothing beyond __invoke().");
    }
});

it('pins the ledger and history models to relationships and table resolution', function (): void {
    expect(declaredMethods(SyncFile::class))->toBe(['getTable', 'runs'])
        ->and(declaredMethods(SyncRun::class))->toBe(['failures', 'file', 'getTable'])
        ->and(declaredMethods(SyncRowFailure::class))->toBe(['getTable', 'run']);
});

it('roots every package exception in one catchable type', function (): void {
    foreach (classesIn('Exceptions') as $exception) {
        $class = 'ByRcsc\\LaravelDataSync\\Exceptions\\'.$exception;

        expect(is_a($class, 'ByRcsc\\LaravelDataSync\\Exceptions\\DataSyncException', true))
            ->toBeTrue("[{$exception}] must extend DataSyncException.");
    }
});
