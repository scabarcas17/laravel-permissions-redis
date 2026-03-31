<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UserDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int|string $userId,
    ) {
    }
}
