<?php

declare(strict_types=1);

it('warns and succeeds when no definitions are enabled', function (): void {
    $this->artisan('sync:run')
        ->expectsOutputToContain('No enabled sync definitions')
        ->assertSuccessful();
});
