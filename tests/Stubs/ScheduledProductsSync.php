<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use Illuminate\Console\Scheduling\Event;

final class ScheduledProductsSync extends ProductSyncDefinition
{
    public function schedule(Event $event): void
    {
        $event->everyFiveMinutes();
    }
}
