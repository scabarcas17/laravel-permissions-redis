<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Resolver;

use Illuminate\Support\Collection;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\LogsMessages;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;

class PermissionResolver implements PermissionResolverInterface
{
    use LogsMessages;

    /** @var array<int, array<string, bool>> */
    private array $permissionCache = [];

    /** @var array<int, array<string, bool>> */
    private array $roleCache = [];

    /** @var array<int, array<string>|null> */
    private array $allPermissionsCache = [];

    /** @var array<int, array<string>|null> */
    private array $allRolesCache = [];

    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
        private readonly AuthorizationCacheManager $cacheManager,
    ) {
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        if (isset($this->permissionCache[$userId][$permission])) {
            return $this->permissionCache[$userId][$permission];
        }

        $this->ensureUserCacheExists($userId);

        $result = $this->repository->userHasPermission($userId, $permission);

        if (!$result && $this->wildcardEnabled()) {
            $result = $this->matchWildcard($userId, $permission);
        }

        $this->permissionCache[$userId][$permission] = $result;

        return $result;
    }

    public function hasRole(int $userId, string $role): bool
    {
        if (isset($this->roleCache[$userId][$role])) {
            return $this->roleCache[$userId][$role];
        }

        $this->ensureUserCacheExists($userId);

        $result = $this->repository->userHasRole($userId, $role);

        $this->roleCache[$userId][$role] = $result;

        return $result;
    }

    /** @return Collection<int, PermissionDTO> */
    public function getAllPermissions(int $userId): Collection
    {
        if (isset($this->allPermissionsCache[$userId])) {
            return collect($this->allPermissionsCache[$userId])
                ->map(fn (string $name): PermissionDTO => new PermissionDTO(name: $name));
        }

        $this->ensureUserCacheExists($userId);

        $names = $this->repository->getUserPermissions($userId);

        $this->allPermissionsCache[$userId] = $names;

        foreach ($names as $name) {
            $this->permissionCache[$userId][$name] = true;
        }

        return collect($names)
            ->map(fn (string $name): PermissionDTO => new PermissionDTO(name: $name));
    }

    /** @return Collection<int, string> */
    public function getAllRoles(int $userId): Collection
    {
        if (isset($this->allRolesCache[$userId])) {
            return collect($this->allRolesCache[$userId]);
        }

        $this->ensureUserCacheExists($userId);

        $roles = $this->repository->getUserRoles($userId);

        $this->allRolesCache[$userId] = $roles;

        foreach ($roles as $role) {
            $this->roleCache[$userId][$role] = true;
        }

        return collect($roles);
    }

    public function flush(): void
    {
        $this->permissionCache = [];
        $this->roleCache = [];
        $this->allPermissionsCache = [];
        $this->allRolesCache = [];
    }

    public function flushUser(int $userId): void
    {
        unset(
            $this->permissionCache[$userId],
            $this->roleCache[$userId],
            $this->allPermissionsCache[$userId],
            $this->allRolesCache[$userId],
        );
    }

    private function ensureUserCacheExists(int $userId): void
    {
        if (!$this->repository->userCacheExists($userId)) {
            $this->log("Auth cache miss for user {$userId}, warming from database.", 'warning');
            $this->cacheManager->warmUser($userId);
        }
    }

    private function isSuperAdmin(int $userId): bool
    {
        /** @var string|null $superAdminRole */
        $superAdminRole = config('permissions-redis.super_admin_role');

        if ($superAdminRole === null) {
            return false;
        }

        return $this->hasRole($userId, $superAdminRole);
    }

    private function wildcardEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('permissions-redis.wildcard_permissions', false);

        return $enabled;
    }

    private function matchWildcard(int $userId, string $permission): bool
    {
        $allPermissions = $this->repository->getUserPermissions($userId);

        foreach ($allPermissions as $stored) {
            if (str_contains($stored, '*') && fnmatch($stored, $permission)) {
                return true;
            }
        }

        return false;
    }
}
