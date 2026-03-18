<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a user's roles are assigned, synced, or removed.
 */
class RolesAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Model $user,
    ) {
    }
}
