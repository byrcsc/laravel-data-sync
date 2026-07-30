<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Support;

use DateTimeInterface;
use RuntimeException;

final readonly class PathTemplate
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function render(string $template, array $values): string
    {
        $rendered = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^{}]+))?\}/',
            function (array $match) use ($values): string {
                $name = $match[1];

                if (! array_key_exists($name, $values)) {
                    throw new RuntimeException("Destination path template references unknown value [{$name}].");
                }

                $value = $values[$name];
                $format = $match[2] ?? null;

                if ($value instanceof DateTimeInterface) {
                    return $value->format(is_string($format) ? $format : 'Y-m-d');
                }

                if (! is_scalar($value)) {
                    throw new RuntimeException("Destination path value [{$name}] cannot be rendered.");
                }

                return (string) $value;
            },
            $template,
        );

        if (! is_string($rendered) || trim($rendered, '/') === '') {
            throw new RuntimeException('Destination path template rendered an empty path.');
        }

        return trim($rendered, '/');
    }
}
