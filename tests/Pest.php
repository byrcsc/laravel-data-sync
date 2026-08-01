<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredMethods(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
            continue;
        }

        $names[] = $method->getName();
    }

    sort($names);

    return $names;
}

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredProperties(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $names[] = $property->getName();
    }

    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function classesIn(string $directory): array
{
    $files = glob(__DIR__.'/../src/'.$directory.'/*.php');
    $names = array_map(
        fn (string $file): string => basename($file, '.php'),
        $files === false ? [] : $files,
    );
    sort($names);

    return $names;
}
