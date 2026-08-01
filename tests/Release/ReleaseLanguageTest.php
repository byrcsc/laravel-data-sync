<?php

declare(strict_types=1);

/**
 * Keeps pre-release language and unsupported product guarantees out of the
 * shipped source, configuration, migration, stubs, and README.
 *
 * The rules are deliberately blunt: a failure here means either a genuine
 * regression or a new, intentional exception that belongs in the allowlist with
 * its reason written down.
 */

/**
 * @return array<string, string>
 */
function shippedFiles(): array
{
    $root = dirname(__DIR__, 2);
    $paths = [
        ...(glob($root.'/src/*.php') ?: []),
        ...(glob($root.'/src/*/*.php') ?: []),
        ...(glob($root.'/config/*.php') ?: []),
        ...(glob($root.'/database/migrations/*.stub') ?: []),
        ...(glob($root.'/stubs/*.stub') ?: []),
        $root.'/README.md',
    ];

    $files = [];

    foreach ($paths as $path) {
        $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
    }

    return $files;
}

/**
 * The governance documents are export-ignored, so they never reach a vendor
 * install, but they are the first thing a contributor or a security reporter
 * reads. They restate the data-handling boundary, so they are held to the same
 * claim checks as the README.
 *
 * @return array<string, string>
 */
function governanceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md'] as $name) {
        $files[$name] = (string) file_get_contents($root.'/'.$name);
    }

    return $files;
}

/**
 * @param  list<string>  $allowed  "path:needle" pairs that are intentional
 * @param  (callable(string): bool)|null  $only  limits the scan to matching paths
 * @return list<string>
 */
function offenders(string $pattern, array $allowed = [], ?callable $only = null): array
{
    $found = [];

    foreach (shippedFiles() as $path => $contents) {
        if ($only !== null && ! $only($path)) {
            continue;
        }

        foreach (explode("\n", $contents) as $number => $line) {
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }

            $needle = strtolower($match[0]);

            foreach ($allowed as $exception) {
                [$allowedPath, $allowedNeedle] = explode(':', $exception, 2);

                if ($path === $allowedPath && str_contains($needle, $allowedNeedle)) {
                    continue 2;
                }
            }

            $found[] = $path.':'.($number + 1).' '.trim($line);
        }
    }

    return $found;
}

it('keeps pre-release language out of the shipped package', function (): void {
    expect(offenders('/\b(pre-1\.0|pre-release|not (yet )?stable|unstable|experimental|under active development|alpha|beta release|use at your own risk)\b/i'))
        ->toBe([]);

    foreach (governanceFiles() as $name => $contents) {
        expect($contents)->not->toMatch(
            '/\b(pre-1\.0|pre-release|not (yet )?stable|experimental|under active development)\b/i',
            $name,
        );
    }
});

it('does not offer a compatibility or deprecation layer on a first release', function (): void {
    expect(offenders('/\b(@deprecated|deprecation period|backwards[- ]compat|backward[- ]compat|compatibility alias)\b/i'))
        ->toBe([]);
});

it('does not claim the ledger or the archive is immutable or tamper-proof', function (): void {
    // The ledger is an ordinary table with an ordinary checksum column. It
    // makes reprocessing a no-op; it does not resist a database administrator.
    expect(offenders('/\b(immutable|immutability|tamper[- ]?proof|tamper[- ]?evident|unalterable|write[- ]?once)\b/i'))
        ->toBe([]);
});

it('does not promise exactly-once or guaranteed delivery', function (): void {
    // Chunks commit independently and a queue can retry a job. "Process once"
    // is a statement about file checksums, not about distributed delivery.
    expect(offenders('/\b(exactly[- ]once|guaranteed delivery|guarantees? that (every|no|all)|never (loses|lose) (a )?(row|record|file)|zero data loss)\b/i'))
        ->toBe([]);
});

it('does not claim the package deletes application records', function (): void {
    // Model pruning for absent rows is the headline entry in Out of scope.
    // `sync:prune` sweeps package history, never the host's models.
    expect(offenders('/\b(delet(e|es|ing) (application|host|your) (records?|models?|rows?)|prunes? (application|host|your) (records?|models?)|removes? records that (no longer|stop) appear)/i'))
        ->toBe([]);
});

it('does not claim two-way sync, API sources, or content extraction', function (): void {
    expect(offenders('/\b(two[- ]way sync|bi[- ]?directional sync|writes? back to (the )?source|API connectors?|OCR|reads? inside (the )?PDFs?)\b/i', allowed: [
        // The README names these only in Out of scope, to deny them.
        'README.md:two-way sync',
        'README.md:api connectors',
        'README.md:ocr',
    ]))->toBe([]);
});

it('describes file identity and the ledger in the documented terms', function (): void {
    $readme = shippedFiles()['README.md'];

    expect($readme)->toContain('SHA-256 checksum of the contents')
        ->and($readme)->toContain('Renaming a file does not')
        ->and($readme)->toContain('Only a successful run blocks reprocessing')
        ->and($readme)->toContain('`sync_files` ledger is never pruned');
});

it('states the limits of a dry run and of chunked commits', function (): void {
    $readme = shippedFiles()['README.md'];

    expect($readme)->toContain('preview and not a guarantee')
        ->and($readme)->toContain('Chunks commit independently')
        ->and($readme)->toContain('all-or-nothing');
});

it('states the data-handling boundary in the security policy', function (): void {
    $policy = governanceFiles()['SECURITY.md'];

    expect($policy)->toContain('Data-handling boundary')
        ->and($policy)->toContain('disabling failed-payload capture')
        ->and($policy)->toContain('cannot make an insecure host');
});

it('keeps the changelog in the released shape', function (): void {
    $changelog = governanceFiles()['CHANGELOG.md'];

    expect($changelog)->toContain('Keep a Changelog')
        ->and($changelog)->toContain('## [Unreleased]')
        ->and($changelog)->toContain('### Added')
        // A first release has nothing to have changed for anyone.
        ->and($changelog)->not->toContain('### Changed')
        ->and($changelog)->not->toContain('### Removed');
});

it('keeps versioned documentation links off the previous major', function (): void {
    // A v1 package linking v0 pages sends a fresh install into 404s.
    expect(offenders('#docs\.rcsc\.dev/laravel-data-sync/v0/#'))->toBe([]);

    foreach (governanceFiles() as $name => $contents) {
        expect($contents)->not->toContain('docs.rcsc.dev/laravel-data-sync/v0/', $name);
    }
});
