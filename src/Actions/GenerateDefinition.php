<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Actions;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class GenerateDefinition
{
    public function __construct(
        private Application $application,
        private Filesystem $files,
    ) {}

    public function __invoke(string $input, bool $transfer, bool $api = false): string
    {
        if (trim($input) === '') {
            throw new RuntimeException('The definition name must not be empty.');
        }

        if ($transfer && $api) {
            throw new RuntimeException('A definition either transfers files or syncs an API. Pass [--transfer] or [--api], not both.');
        }

        $suffix = $transfer ? 'Transfer' : 'Sync';
        $class = Str::studly(class_basename(str_replace('/', '\\', $input)));
        $class = Str::endsWith($class, $suffix) ? $class : $class.$suffix;
        $path = $this->application->path("Syncs/{$class}.php");

        if ($this->files->exists($path)) {
            throw new RuntimeException("Definition [{$path}] already exists.");
        }

        $stub = $this->files->get(
            dirname(__DIR__, 2).'/stubs/'.$this->stub($transfer, $api),
        );

        try {
            $namespace = trim($this->application->getNamespace(), '\\');
        } catch (RuntimeException) {
            $namespace = 'App';
        }

        $contents = str_replace(
            ['{{ namespace }}', '{{ rootNamespace }}', '{{ class }}', '{{ name }}'],
            [$namespace.'\\Syncs', $namespace.'\\', $class, Str::kebab(Str::beforeLast($class, $suffix))],
            $stub,
        );

        $this->files->ensureDirectoryExists(dirname($path));

        if ($this->files->put($path, $contents) === false) {
            throw new RuntimeException("Definition [{$path}] could not be written.");
        }

        return $path;
    }

    private function stub(bool $transfer, bool $api): string
    {
        if ($transfer) {
            return 'transfer.stub';
        }

        return $api ? 'api-sync.stub' : 'sync.stub';
    }
}
