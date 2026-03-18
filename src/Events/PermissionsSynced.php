<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a role's permissions are synced (created or updated).
 */
class PermissionsSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $role,
    ) {
    }
}
