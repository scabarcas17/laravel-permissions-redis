<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Events\RolesAssigned;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;

/**
 * @mixin Model
 *
 * @property int $id
 */
trait HasRedisPermissions
{
    public function hasPermissionTo(string|BackedEnum $permission, ?string $guardName = null): bool
    {
        $permissionName = $permission instanceof BackedEnum ? (string) $permission->value : $permission;

        return $this->getPermissionResolver()->hasPermission($this->id, $permissionName);
    }

    public function hasAnyPermission(mixed ...$permissions): bool
    {
        $permissions = $this->flattenPermissions($permissions);

        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(mixed ...$permissions): bool
    {
        $permissions = $this->flattenPermissions($permissions);

        foreach ($permissions as $permission) {
            if (!$this->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum|Collection $roles
     */
    public function hasRole(mixed $roles, ?string $guardName = null): bool
    {
        if (is_string($roles)) {
            return $this->getPermissionResolver()->hasRole($this->id, $roles);
        }

        if ($roles instanceof BackedEnum) {
            return $this->getPermissionResolver()->hasRole($this->id, (string) $roles->value);
        }

        if (is_int($roles)) {
            $roleName = Role::query()->where('id', $roles)->value('name');

            return $roleName !== null && $this->getPermissionResolver()->hasRole($this->id, $roleName);
        }

        $items = $roles instanceof Collection ? $roles->all() : (array) $roles;

        foreach ($items as $role) {
            if ($this->hasRole($role, $guardName)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(mixed ...$roles): bool
    {
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllRoles(mixed ...$roles): bool
    {
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        foreach ($roles as $role) {
            if (!$this->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    public function getAllPermissions(): Collection
    {
        return $this->getPermissionResolver()->getAllPermissions($this->id);
    }

    public function getPermissionNames(): Collection
    {
        return $this->getAllPermissions()->pluck('name');
    }

    public function getRoleNames(): Collection
    {
        return $this->getPermissionResolver()->getAllRoles($this->id);
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$roles
     */
    public function assignRole(mixed ...$roles): static
    {
        $roleIds = $this->resolveRoleIds($roles);

        $this->roles()->syncWithoutDetaching($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$roles
     */
    public function syncRoles(mixed ...$roles): static
    {
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        $roleIds = $this->resolveRoleIds($roles);

        $this->roles()->sync($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    public function removeRole(mixed $role): static
    {
        $roleIds = $this->resolveRoleIds([$role]);

        $this->roles()->detach($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$permissions
     */
    public function givePermissionTo(mixed ...$permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->syncWithoutDetaching($permissionIds);

        $this->invalidatePermissionsCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$permissions
     */
    public function revokePermissionTo(mixed ...$permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->detach($permissionIds);

        $this->invalidatePermissionsCache();

        return $this;
    }

    /**
     * @param array<string|int|BackedEnum> $permissions
     */
    public function syncPermissions(array $permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->sync($permissionIds);

        $this->invalidatePermissionsCache();

        return $this;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Role::class,
            table: config('permissions-redis.tables.model_has_roles', 'model_has_roles'),
            foreignPivotKey: 'model_id',
            relatedPivotKey: 'role_id',
        )
            ->wherePivot('model_type', static::class)
            ->withPivotValue('model_type', static::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Permission::class,
            table: config('permissions-redis.tables.model_has_permissions', 'model_has_permissions'),
            foreignPivotKey: 'model_id',
            relatedPivotKey: 'permission_id',
        )
            ->wherePivot('model_type', static::class)
            ->withPivotValue('model_type', static::class);
    }

    /** @param string|int|array<string|int> $roles */
    public function scopeRole($query, mixed $roles, ?string $guard = null)
    {
        $roles = is_array($roles) ? $roles : [$roles];

        $roleIds = collect($roles)->map(function (mixed $role): int {
            if (is_int($role)) {
                return $role;
            }

            /** @var Role $model */
            $model = Role::query()->where('name', $role)->firstOrFail();

            return $model->id;
        });

        return $query->whereHas('roles', function ($q) use ($roleIds) {
            $q->whereIn('roles.id', $roleIds);
        });
    }

    /** @param string|int|array<string|int> $permissions */
    public function scopePermission($query, mixed $permissions, ?string $guard = null)
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        $permissionIds = collect($permissions)->map(function (mixed $perm): int {
            if (is_int($perm)) {
                return $perm;
            }

            /** @var Permission $model */
            $model = Permission::query()->where('name', $perm)->firstOrFail();

            return $model->id;
        });

        return $query->where(function ($q) use ($permissionIds) {
            $q->whereHas('roles', function ($q) use ($permissionIds) {
                $q->whereHas('permissions', function ($q) use ($permissionIds) {
                    $q->whereIn('permissions.id', $permissionIds);
                });
            })->orWhereHas('permissions', function ($q) use ($permissionIds) {
                $q->whereIn('permissions.id', $permissionIds);
            });
        });
    }

    private function getPermissionResolver(): PermissionResolverInterface
    {
        /** @var PermissionResolverInterface $resolver */
        $resolver = app(PermissionResolverInterface::class);

        return $resolver;
    }

    /**
     * @param array<mixed> $roles
     *
     * @return array<int>
     */
    private function resolveRoleIds(array $roles): array
    {
        return collect($roles)
            ->flatten()
            ->map(function (mixed $role): int {
                if (is_int($role)) {
                    return $role;
                }

                if ($role instanceof BackedEnum) {
                    $role = (string) $role->value;
                }

                if (is_string($role)) {
                    /** @var Role $model */
                    $model = Role::query()->where('name', $role)->firstOrFail();

                    return $model->id;
                }

                return (int) $role;
            })
            ->all();
    }

    /**
     * @return array<int>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        return collect($permissions)
            ->flatten()
            ->map(function (mixed $permission): int {
                if (is_int($permission)) {
                    return $permission;
                }

                if ($permission instanceof BackedEnum) {
                    $permission = (string) $permission->value;
                }

                if (is_string($permission)) {
                    /** @var Permission $model */
                    $model = Permission::query()->where('name', $permission)->firstOrFail();

                    return $model->id;
                }

                return (int) $permission;
            })
            ->all();
    }

    /**
     * @return array<string>
     */
    private function flattenPermissions(array $permissions): array
    {
        return collect($permissions)
            ->flatten()
            ->map(function (mixed $permission): string {
                if ($permission instanceof BackedEnum) {
                    return (string) $permission->value;
                }

                return (string) $permission;
            })
            ->all();
    }

    private function invalidateRolesCache(): void
    {
        event(new RolesAssigned($this));
        $this->getPermissionResolver()->flushUser($this->id);
    }

    private function invalidatePermissionsCache(): void
    {
        $this->getPermissionResolver()->flushUser($this->id);

        /** @var AuthorizationCacheManager $cacheManager */
        $cacheManager = app(AuthorizationCacheManager::class);
        $cacheManager->warmUser($this->id);
    }
}
