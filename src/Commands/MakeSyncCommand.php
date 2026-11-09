<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Commands;

use ByRcsc\LaravelDataSync\Actions\GenerateDefinition;
use RuntimeException;
use Throwable;

final class MakeSyncCommand extends Command
{
    protected $signature = 'make:sync
        {name : The definition class name}
        {--transfer : Generate a file transfer definition}
        {--api : Generate a definition that pulls from an HTTP API}
        {--database : Generate a definition that reads from a database connection}';

    protected $description = 'Create a data sync, API sync, database sync, or file transfer definition';

    public function handle(GenerateDefinition $generate): int
    {
        try {
            $path = $generate(
                $this->definitionName($this->argument('name')),
                (bool) $this->option('transfer'),
                (bool) $this->option('api'),
                (bool) $this->option('database'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Definition [{$path}] created successfully.");

        return self::SUCCESS;
    }

    private function definitionName(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('The definition name must be a string.');
        }

        return $value;
    }
}
