<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Traits;

use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;

/**
 * Auto-dispatches UserDeleted so the Redis cache is invalidated when
 * the consuming model is deleted. Add to your User model alongside
 * HasRedisPermissions.
 *
 * @mixin Model
 */
trait DispatchesPermissionEvents
{
    public static function bootDispatchesPermissionEvents(): void
    {
        static::deleted(function (Model $model): void {
            $id = $model->getKey();

            if (!is_int($id) && !is_string($id)) {
                return;
            }

            event(new UserDeleted($id));
        });
    }
}
