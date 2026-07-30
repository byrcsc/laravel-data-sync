<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\GenerateDefinition;
use Throwable;

final class MakeSyncCommand extends Command
{
    protected $signature = 'make:sync
        {name : The definition class name}
        {--transfer : Generate a file transfer definition}';

    protected $description = 'Create a data sync or file transfer definition';

    public function handle(GenerateDefinition $generate): int
    {
        try {
            $path = $generate(
                $this->argument('name'),
                (bool) $this->option('transfer'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Definition [{$path}] created successfully.");

        return self::SUCCESS;
    }
}
