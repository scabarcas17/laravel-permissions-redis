<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $guard_name
 */
class Role extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'guard_name'];

    public static function findOrCreate(string $name, string $guardName = 'web'): static
    {
        if (str_contains($name, '|')) {
            throw new InvalidArgumentException("Role name cannot contain the '|' character.");
        }

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

    /** @param array<string|int|BackedEnum> $permissions */
    public function syncPermissions(array $permissions): static
    {
        $this->permissions()->sync($this->resolvePermissionIds($permissions));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum ...$permissions */
    public function givePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->syncWithoutDetaching($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum ...$permissions */
    public function revokePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->detach($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    protected static function booted(): void
    {
        static::deleted(function (Role $role): void {
            event(new RoleDeleted($role->id));
        });
    }

    /**
     * @param array<mixed> $permissions
     *
     * @return array<int>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $intIds = [];
        $names = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof BackedEnum) {
                $names[] = (string) $permission->value;
            } elseif (is_string($permission)) {
                $names[] = $permission;
            } else {
                $intIds[] = is_numeric($permission) ? (int) $permission : 0;
            }
        }

        if ($names !== []) {
            $resolved = Permission::query()
                ->where('guard_name', $this->guard_name)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            $intIds = array_merge($intIds, $resolved);
        }

        return $intIds;
    }
}
