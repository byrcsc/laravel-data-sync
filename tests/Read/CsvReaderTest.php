<?php

declare(strict_types=1);

use ByRcsc\LaravelDataSync\Definitions\Format;

it('reads header based CSV with delimiter and enclosure options', function (): void {
    $stream = fopen(__DIR__.'/../Fixtures/semicolon.csv', 'rb');
    $reader = Format::csv()->delimiter(';');

    expect($reader->header($stream))->toBe(['SKU', 'NAME', 'PRICE']);
    fclose($stream);

    $stream = fopen(__DIR__.'/../Fixtures/semicolon.csv', 'rb');
    $rows = iterator_to_array($reader->rows($stream));
    fclose($stream);

    expect($rows)->toBe([
        2 => ['SKU' => 'S-1', 'NAME' => 'Semicolon product', 'PRICE' => '2.50'],
    ]);
});

it('reads arbitrary delimited text in index mode', function (): void {
    $stream = fopen(__DIR__.'/../Fixtures/headerless.txt', 'rb');
    $reader = Format::delimited()->delimiter('|')->withoutHeader();

    expect($reader->header($stream))->toBe([0, 1, 2]);
    fclose($stream);

    $stream = fopen(__DIR__.'/../Fixtures/headerless.txt', 'rb');
    $rows = iterator_to_array($reader->rows($stream));
    fclose($stream);

    expect($rows[1])->toBe(['I-1', 'Index product', '4.25'])
        ->and($rows[2])->toBe(['I-2', 'Second product', '5.00']);
});

it('strips UTF-8 BOM and converts configured encodings', function (): void {
    $bom = fopen('php://memory', 'w+b');
    fwrite($bom, "\xEF\xBB\xBFSKU,NAME\nA-1,Alpha\n");
    rewind($bom);

    expect(Format::csv()->header($bom))->toBe(['SKU', 'NAME']);
    fclose($bom);

    $latin = fopen('php://memory', 'w+b');
    fwrite($latin, iconv('UTF-8', 'ISO-8859-1', "SKU,NAME\nA-1,Café\n"));
    rewind($latin);
    $rows = iterator_to_array(Format::csv()->encoding('ISO-8859-1')->rows($latin));
    fclose($latin);

    expect($rows[2]['NAME'])->toBe('Café');
});

it('yields large inputs lazily', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "SKU,NAME\n");

    for ($index = 1; $index <= 5000; $index++) {
        fwrite($stream, "SKU-{$index},Product {$index}\n");
    }

    $length = ftell($stream);
    rewind($stream);
    $rows = Format::csv()->rows($stream);
    $rows->rewind();

    expect($rows->current())->toBe(['SKU' => 'SKU-1', 'NAME' => 'Product 1'])
        ->and(ftell($stream))->toBeLessThan($length);

    fclose($stream);
});
