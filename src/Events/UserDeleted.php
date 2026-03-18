<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a user is deleted.
 */
class UserDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
    ) {
    }
}
