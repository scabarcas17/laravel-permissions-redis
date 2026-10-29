<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Scabarcas\LaravelPermissionsRedis\Traits\DispatchesPermissionEvents;

/**
 * A model set up the pre-4.2.0 way, with both traits: HasRedisPermissions
 * (inherited) plus the now-redundant DispatchesPermissionEvents.
 */
class DualTraitUser extends User
{
    use DispatchesPermissionEvents;

    protected $table = 'users';
}
