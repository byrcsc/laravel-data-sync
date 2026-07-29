<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use ByRcsc\LaravelDataSync\Definitions\SyncDefinition;
use ByRcsc\LaravelDataSync\Enums\RunStatus;
use ByRcsc\LaravelDataSync\Enums\SyncFileStatus;
use ByRcsc\LaravelDataSync\Events\SyncCompleted;
use ByRcsc\LaravelDataSync\Events\SyncFailed;
use ByRcsc\LaravelDataSync\Models\SyncFile;
use ByRcsc\LaravelDataSync\Models\SyncRun;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final readonly class FinalizeRun
{
    public function __construct(
        private ArchiveFile $archiveFile,
        private Dispatcher $events,
    ) {}

    public function complete(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
    ): SyncRun {
        $run->refresh();
        $status = $run->rows_failed > 0
            ? RunStatus::CompletedWithErrors
            : RunStatus::Completed;
        $archivePath = null;

        if ($run->dry_run) {
            $this->archiveFile->discardStaged($definition, $file);
        } else {
            $archivePath = ($this->archiveFile)($definition, $file, $run);
            $file->forceFill([
                'status' => SyncFileStatus::Completed,
                'last_processed_at' => now(),
            ])->save();
        }

        $run->forceFill([
            'status' => $status,
            'archive_path' => $archivePath,
            'error_summary' => $this->failureSummary($run),
            'finished_at' => now(),
        ])->save();
        $completed = $run->fresh() ?? $run;
        $this->events->dispatch(new SyncCompleted($completed));

        return $completed;
    }

    public function fail(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
        Throwable $exception,
    ): SyncRun {
        $archivePath = null;

        if ($run->dry_run) {
            $this->archiveFile->discardStaged($definition, $file);
        } else {
            $file->forceFill(['status' => SyncFileStatus::Failed])->save();

            try {
                $archivePath = $this->archiveFile->preserveFailed($definition, $file, $run);
            } catch (Throwable $preservationException) {
                $exception = $preservationException;
            }
        }

        $run->forceFill([
            'status' => RunStatus::Failed,
            'archive_path' => $archivePath,
            'error_summary' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();
        $failed = $run->fresh() ?? $run;
        $this->events->dispatch(new SyncFailed($failed));

        return $failed;
    }

    public function cancel(
        SyncDefinition $definition,
        SyncFile $file,
        SyncRun $run,
    ): SyncRun {
        $archivePath = $this->archiveFile->preserveFailed($definition, $file, $run);
        $file->forceFill(['status' => SyncFileStatus::Failed])->save();
        $run->forceFill([
            'status' => RunStatus::Cancelled,
            'archive_path' => $archivePath,
            'error_summary' => 'Queue batch was cancelled.',
            'finished_at' => now(),
        ])->save();
        $cancelled = $run->fresh() ?? $run;
        $this->events->dispatch(new SyncFailed($cancelled));

        return $cancelled;
    }

    private function failureSummary(SyncRun $run): ?string
    {
        if ($run->rows_failed === 0) {
            return null;
        }

        $messages = [];

        foreach ($run->failures()->get(['errors']) as $failure) {
            foreach ($failure->errors as $field => $errors) {
                foreach ($errors as $error) {
                    $key = "{$field}: {$error}";
                    $messages[$key] = ($messages[$key] ?? 0) + 1;
                }
            }
        }

        return json_encode($messages, JSON_THROW_ON_ERROR);
    }
}
