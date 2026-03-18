<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $guard_name
 */
class Role extends Model
{
    protected $guarded = ['id'];

    public static function findOrCreate(string $name, string $guardName = 'web'): static
    {
        /** @var static $role */
        $role = static::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => $guardName],
        );

        return $role;
    }

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('permissions-redis.tables.roles', 'roles');

        return $table;
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        /** @var string $table */
        $table = config('permissions-redis.tables.role_has_permissions', 'role_has_permissions');

        return $this->belongsToMany(
            related: Permission::class,
            table: $table,
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }

    /** @return BelongsToMany<Model, $this> */
    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

        /** @var string $table */
        $table = config('permissions-redis.tables.model_has_roles', 'model_has_roles');

        return $this->belongsToMany(
            related: $userModel,
            table: $table,
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'model_id',
        );
    }

    protected static function booted(): void
    {
        static::deleted(function (Role $role): void {
            event(new RoleDeleted($role->id));
        });
    }
}
