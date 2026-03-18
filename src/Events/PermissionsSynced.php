<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class PermissionsSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $role,
    ) {
    }
}
