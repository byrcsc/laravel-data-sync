<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Definitions;

use DateTimeImmutable;
use RuntimeException;

final readonly class FilenamePattern
{
    public function __construct(
        private string $pattern,
    ) {}

    /**
     * @return array<string, string|DateTimeImmutable>|null
     */
    public function match(string $filename): ?array
    {
        $placeholders = [];
        $offset = 0;
        $regex = '';
        $matches = [];
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^{}]+))?\}/', $this->pattern, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$token, $position]) {
            $regex .= preg_quote(substr($this->pattern, $offset, $position - $offset), '/');
            $name = $matches[1][$index][0];

            if (array_key_exists($name, $placeholders)) {
                throw new RuntimeException("matchFilename() pattern [{$this->pattern}] repeats segment [{$name}]. Use each segment name once.");
            }

            $format = $matches[2][$index][0] ?? '';
            $placeholders[$name] = $format !== '' ? $format : null;
            $regex .= '(?P<'.$name.'>.+?)';
            $offset = $position + strlen($token);
        }

        $regex .= preg_quote(substr($this->pattern, $offset), '/');

        if ($placeholders === []) {
            throw new RuntimeException("matchFilename() pattern [{$this->pattern}] must contain at least one {segment}.");
        }

        $values = [];

        if (preg_match('/^'.$regex.'$/', $filename, $values) !== 1) {
            return null;
        }

        $segments = [];

        foreach ($placeholders as $name => $format) {
            $value = $values[$name];

            if ($format === null) {
                $segments[$name] = $value;

                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RuntimeException(
                    "Filename [{$filename}] has an invalid [{$name}] date segment [{$value}] for matchFilename() format [{$format}]. Correct the source filename or the pattern.",
                );
            }

            $segments[$name] = $date;
        }

        return $segments;
    }
}
