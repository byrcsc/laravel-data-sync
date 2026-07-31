<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Definitions\Reader;

it('reports the configured path when a JSON row array cannot be found', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, '{"payload":{"items":[]}}');
    rewind($stream);

    expect(fn (): array => iterator_to_array(
        Reader::json()->path('payload.products')->rows($stream),
    ))->toThrow(RuntimeException::class, 'JSON row path [payload.products] does not exist');

    fclose($stream);
});

it('reports the source line for malformed NDJSON', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "{\"SKU\":\"A-1\"}\nnot-json\n");
    rewind($stream);

    expect(fn (): array => iterator_to_array(Reader::ndjson()->rows($stream)))
        ->toThrow(RuntimeException::class, 'NDJSON line [2]');

    fclose($stream);
});

it('yields NDJSON without reading the complete stream', function (): void {
    $stream = fopen('php://memory', 'w+b');

    for ($index = 1; $index <= 5000; $index++) {
        fwrite($stream, json_encode(['SKU' => "SKU-{$index}"], JSON_THROW_ON_ERROR)."\n");
    }

    $length = ftell($stream);
    rewind($stream);
    $rows = Reader::ndjson()->rows($stream);
    $rows->rewind();

    expect($rows->current())->toBe(['SKU' => 'SKU-1'])
        ->and(ftell($stream))->toBeLessThan($length);

    fclose($stream);
});

it('rejects invalid fixed-width ranges', function (): void {
    expect(fn () => Reader::fixedWidth()->column('SKU', start: -1, length: 10))
        ->toThrow(InvalidArgumentException::class, 'start must be zero or greater')
        ->and(fn () => Reader::fixedWidth()->column('SKU', start: 0, length: 0))
        ->toThrow(InvalidArgumentException::class, 'length must be greater than zero');
});

it('discovers JSON columns introduced after the first row', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, '[{"SKU":"A-1"},{"SKU":"A-2","EXTRA":"later"}]');
    rewind($stream);

    expect(Reader::json()->header($stream))->toBe(['SKU', 'EXTRA']);

    fclose($stream);
});

it('discovers NDJSON columns introduced after the first row without buffering rows', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "{\"SKU\":\"A-1\"}\n{\"SKU\":\"A-2\",\"EXTRA\":\"later\"}\n");
    rewind($stream);

    expect(Reader::ndjson()->header($stream))->toBe(['SKU', 'EXTRA']);

    fclose($stream);
});

it('rejects truncated fixed-width records with the source line', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "short\n");
    rewind($stream);
    $reader = Reader::fixedWidth()->column('SKU', start: 0, length: 8);

    expect(fn (): array => iterator_to_array($reader->rows($stream)))
        ->toThrow(RuntimeException::class, 'Fixed-width line [1] is 5 bytes');

    fclose($stream);
});
