<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $guard_name
 */
class Role extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('permissions-redis.tables.roles', 'roles');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Permission::class,
            table: config('permissions-redis.tables.role_has_permissions', 'role_has_permissions'),
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }

    /** @return BelongsToMany<Model, $this> */
    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

        return $this->belongsToMany(
            related: $userModel,
            table: config('permissions-redis.tables.model_has_roles', 'model_has_roles'),
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'model_id',
        );
    }
}
