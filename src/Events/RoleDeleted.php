<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RoleDeleted
{
    use Dispatchable;

    /**
     * @param array<int|string> $affectedUserIds User IDs captured from the
     *                                           database before the delete;
     *                                           the pivot rows are gone by the
     *                                           time listeners run (FK cascade).
     */
    public function __construct(
        public readonly int $roleId,
        public readonly array $affectedUserIds = [],
    ) {
    }
}
