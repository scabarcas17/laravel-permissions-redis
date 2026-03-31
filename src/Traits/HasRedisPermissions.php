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
 * @property int|string $id
 */
trait HasRedisPermissions
{
    private ?string $guardOverride = null;

    public function forGuard(string $guard): static
    {
        $this->guardOverride = $guard;

        return $this;
    }

    public function hasPermissionTo(string|BackedEnum $permission, ?string $guardName = null): bool
    {
        $override = $this->consumeGuardOverride();
        $guardName ??= $override;

        $permissionName = $permission instanceof BackedEnum ? (string) $permission->value : $permission;

        return $this->getPermissionResolver()->hasPermission($this->id, $permissionName, $guardName);
    }

    public function hasAnyPermission(mixed ...$permissions): bool
    {
        $guard = $this->consumeGuardOverride();
        $permissions = $this->flattenPermissions($permissions);

        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission, $guard)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(mixed ...$permissions): bool
    {
        $guard = $this->consumeGuardOverride();
        $permissions = $this->flattenPermissions($permissions);

        foreach ($permissions as $permission) {
            if (!$this->hasPermissionTo($permission, $guard)) {
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
        $override = $this->consumeGuardOverride();
        $guardName ??= $override;

        $items = match (true) {
            is_string($roles), is_int($roles), $roles instanceof BackedEnum => [$roles],
            $roles instanceof Collection => $roles->all(),
            default                      => (array) $roles,
        };

        $resolver = $this->getPermissionResolver();

        foreach ($items as $role) {
            $name = match (true) {
                $role instanceof BackedEnum => (string) $role->value,
                is_int($role)               => Role::query()->where('id', $role)->value('name'),
                default                     => (string) $role,
            };

            if ($name !== null && $resolver->hasRole($this->id, $name, $guardName)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyRole(mixed ...$roles): bool
    {
        $guard = $this->consumeGuardOverride();
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        foreach ($roles as $role) {
            if ($this->hasRole($role, $guard)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllRoles(mixed ...$roles): bool
    {
        $guard = $this->consumeGuardOverride();
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        foreach ($roles as $role) {
            if (!$this->hasRole($role, $guard)) {
                return false;
            }
        }

        return true;
    }

    public function getAllPermissions(?string $guard = null): Collection
    {
        $override = $this->consumeGuardOverride();
        $guard ??= $override;

        return $this->getPermissionResolver()->getAllPermissions($this->id, $guard);
    }

    public function getPermissionNames(?string $guard = null): Collection
    {
        $override = $this->consumeGuardOverride();
        $guard ??= $override;

        return $this->getAllPermissions($guard)->pluck('name');
    }

    public function getRoleNames(?string $guard = null): Collection
    {
        $override = $this->consumeGuardOverride();
        $guard ??= $override;

        return $this->getPermissionResolver()->getAllRoles($this->id, $guard);
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$roles
     */
    public function assignRole(mixed ...$roles): static
    {
        $guard = $this->consumeGuardOverride();
        $roleIds = $this->resolveRoleIds($roles, $guard);

        $this->roles()->syncWithoutDetaching($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$roles
     */
    public function syncRoles(mixed ...$roles): static
    {
        $guard = $this->consumeGuardOverride();
        $roles = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        $roleIds = $this->resolveRoleIds($roles, $guard);

        $this->roles()->sync($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    public function removeRole(mixed $role): static
    {
        $guard = $this->consumeGuardOverride();
        $roleIds = $this->resolveRoleIds([$role], $guard);

        $this->roles()->detach($roleIds);

        $this->invalidateRolesCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$permissions
     */
    public function givePermissionTo(mixed ...$permissions): static
    {
        $guard = $this->consumeGuardOverride();
        $permissionIds = $this->resolvePermissionIds($permissions, $guard);

        $this->permissions()->syncWithoutDetaching($permissionIds);

        $this->invalidatePermissionsCache();

        return $this;
    }

    /**
     * @param string|int|array<string|int>|BackedEnum ...$permissions
     */
    public function revokePermissionTo(mixed ...$permissions): static
    {
        $guard = $this->consumeGuardOverride();
        $permissionIds = $this->resolvePermissionIds($permissions, $guard);

        $this->permissions()->detach($permissionIds);

        $this->invalidatePermissionsCache();

        return $this;
    }

    /**
     * @param array<string|int|BackedEnum> $permissions
     */
    public function syncPermissions(array $permissions): static
    {
        $guard = $this->consumeGuardOverride();
        $permissionIds = $this->resolvePermissionIds($permissions, $guard);

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

        /** @var string $guardName */
        $guardName = $guard ?? config('auth.defaults.guard', 'web');

        $roleIds = $this->batchResolveRoleIds($roles, $guardName);

        return $query->whereHas('roles', function ($q) use ($roleIds) {
            $q->whereIn('roles.id', $roleIds);
        });
    }

    /** @param string|int|array<string|int> $permissions */
    public function scopePermission($query, mixed $permissions, ?string $guard = null)
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        /** @var string $guardName */
        $guardName = $guard ?? config('auth.defaults.guard', 'web');

        $permissionIds = $this->batchResolvePermissionIds($permissions, $guardName);

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

    private function consumeGuardOverride(): ?string
    {
        $guard = $this->guardOverride;
        $this->guardOverride = null;

        return $guard;
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
    private function resolveRoleIds(array $roles, ?string $guard = null): array
    {
        /** @var string $defaultGuard */
        $defaultGuard = $guard ?? config('auth.defaults.guard', 'web');

        return $this->batchResolveRoleIds(collect($roles)->flatten()->all(), $defaultGuard);
    }

    /**
     * @param array<mixed> $permissions
     *
     * @return array<int>
     */
    private function resolvePermissionIds(array $permissions, ?string $guard = null): array
    {
        /** @var string $defaultGuard */
        $defaultGuard = $guard ?? config('auth.defaults.guard', 'web');

        return $this->batchResolvePermissionIds(collect($permissions)->flatten()->all(), $defaultGuard);
    }

    /**
     * Resolve a mixed list of role identifiers to IDs using a single query for names.
     *
     * @param array<mixed> $roles
     *
     * @return array<int>
     */
    private function batchResolveRoleIds(array $roles, string $guard): array
    {
        $intIds = [];
        $names = [];

        foreach ($roles as $role) {
            if (is_int($role)) {
                $intIds[] = $role;
            } elseif ($role instanceof BackedEnum) {
                $names[] = (string) $role->value;
            } elseif (is_string($role)) {
                $names[] = $role;
            } else {
                $intIds[] = (int) $role;
            }
        }

        if ($names !== []) {
            $resolved = Role::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $intIds = array_merge($intIds, $resolved);
        }

        return $intIds;
    }

    /**
     * Resolve a mixed list of permission identifiers to IDs using a single query for names.
     *
     * @param array<mixed> $permissions
     *
     * @return array<int>
     */
    private function batchResolvePermissionIds(array $permissions, string $guard): array
    {
        $intIds = [];
        $names = [];

        foreach ($permissions as $permission) {
            if (is_int($permission)) {
                $intIds[] = $permission;
            } elseif ($permission instanceof BackedEnum) {
                $names[] = (string) $permission->value;
            } elseif (is_string($permission)) {
                $names[] = $permission;
            } else {
                $intIds[] = (int) $permission;
            }
        }

        if ($names !== []) {
            $resolved = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $intIds = array_merge($intIds, $resolved);
        }

        return $intIds;
    }

    /**
     * @param array<mixed> $permissions
     *
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
