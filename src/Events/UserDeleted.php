<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UserDeleted
{
    use Dispatchable;

    /**
     * @param array<int> $affectedRoleIds Roles the user held, captured
     *                                    before the pivot rows were removed;
     *                                    their role:{id}:users indexes are
     *                                    rebuilt without the user.
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly array $affectedRoleIds = [],
    ) {
    }
}
