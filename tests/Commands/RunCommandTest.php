<?php

declare(strict_types=1);

it('allows queued execution by default', function (): void {
    $this->artisan('sync:run')
        ->expectsOutputToContain('No enabled sync definitions')
        ->assertSuccessful();
});
