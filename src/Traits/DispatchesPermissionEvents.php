<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Traits;

use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;

/**
 * Dispatches UserDeleted when the consuming model is deleted so the Redis
 * cache is invalidated.
 *
 * @deprecated since 4.2.0: HasRedisPermissions dispatches UserDeleted itself
 *             (and removes the pivot rows on a hard delete). The trait stays
 *             a no-op on models that also use HasRedisPermissions, so adding
 *             both keeps working; it will be removed in 5.0.
 *
 * @mixin Model
 */
trait DispatchesPermissionEvents
{
    public static function bootDispatchesPermissionEvents(): void
    {
        if (in_array(HasRedisPermissions::class, class_uses_recursive(static::class), true)) {
            return;
        }

        static::deleted(function (Model $model): void {
            $id = $model->getKey();

            if (!is_int($id) && !is_string($id)) {
                return;
            }

            event(new UserDeleted($id));
        });
    }
}
