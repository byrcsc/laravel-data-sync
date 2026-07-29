<?php

declare(strict_types=1);

it('requires explicit synchronous execution until the queue layer exists', function (): void {
    $this->artisan('sync:run')
        ->expectsOutputToContain('Re-run with --now')
        ->assertFailed();
});
