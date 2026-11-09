<?php

declare(strict_types=1);

namespace ByRcsc\LaravelDataSync\Tests\Stubs;

use ByRcsc\LaravelDataSync\Contracts\DefinitionProvider;
use stdClass;

final class WrongItemDefinitionProvider implements DefinitionProvider
{
    public function definitions(): iterable
    {
        yield new stdClass;
    }
}
