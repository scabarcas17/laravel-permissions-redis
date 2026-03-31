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

    /** @var array<int|string, array<string, bool>> */
    private array $permissionCache = [];

    /** @var array<int|string, array<string, bool>> */
    private array $roleCache = [];

    /** @var array<int|string, array<string>|null> */
    private array $allPermissionsCache = [];

    /** @var array<int|string, array<string>|null> */
    private array $allRolesCache = [];

    /** @var array<int|string, bool> */
    private array $superAdminCache = [];

    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
        private readonly AuthorizationCacheManager $cacheManager,
    ) {
    }

    public function hasPermission(int|string $userId, string $permission, ?string $guard = null): bool
    {
        $guard = $this->resolveGuard($guard);
        $cacheKey = "{$guard}|{$permission}";

        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        if (isset($this->permissionCache[$userId][$cacheKey])) {
            return $this->permissionCache[$userId][$cacheKey];
        }

        $this->ensureUserCacheExists($userId);

        $result = $this->repository->userHasPermission($userId, $cacheKey);

        if (!$result && $this->wildcardEnabled()) {
            $result = $this->matchWildcard($userId, $permission, $guard);
        }

        $this->permissionCache[$userId][$cacheKey] = $result;

        return $result;
    }

    public function hasRole(int|string $userId, string $role, ?string $guard = null): bool
    {
        $guard = $this->resolveGuard($guard);
        $cacheKey = "{$guard}|{$role}";

        if (isset($this->roleCache[$userId][$cacheKey])) {
            return $this->roleCache[$userId][$cacheKey];
        }

        $this->ensureUserCacheExists($userId);

        $result = $this->repository->userHasRole($userId, $cacheKey);

        $this->roleCache[$userId][$cacheKey] = $result;

        return $result;
    }

    /** @return Collection<int, PermissionDTO> */
    public function getAllPermissions(int|string $userId, ?string $guard = null): Collection
    {
        $names = $this->loadUserPermissions($userId);

        return collect($names)
            ->map(fn (string $encoded): array => $this->decodeEntry($encoded))
            ->when($guard !== null, fn (Collection $c) => $c->filter(fn (array $entry): bool => $entry[0] === $guard))
            ->map(fn (array $entry): PermissionDTO => new PermissionDTO(name: $entry[1], guard: $entry[0]))
            ->values();
    }

    /** @return Collection<int, string> */
    public function getAllRoles(int|string $userId, ?string $guard = null): Collection
    {
        $roles = $this->loadUserRoles($userId);

        return collect($roles)
            ->map(fn (string $encoded): array => $this->decodeEntry($encoded))
            ->when($guard !== null, fn (Collection $c) => $c->filter(fn (array $entry): bool => $entry[0] === $guard))
            ->map(fn (array $entry): string => $entry[1])
            ->values();
    }

    public function flush(): void
    {
        $this->permissionCache = [];
        $this->roleCache = [];
        $this->allPermissionsCache = [];
        $this->allRolesCache = [];
        $this->superAdminCache = [];
    }

    public function flushUser(int|string $userId): void
    {
        unset(
            $this->permissionCache[$userId],
            $this->roleCache[$userId],
            $this->allPermissionsCache[$userId],
            $this->allRolesCache[$userId],
            $this->superAdminCache[$userId],
        );
    }

    private function ensureUserCacheExists(int|string $userId): void
    {
        if (!$this->repository->userCacheExists($userId)) {
            $this->log("Auth cache miss for user {$userId}, warming from database.", 'warning');
            $this->cacheManager->warmUser($userId);
        }
    }

    private function isSuperAdmin(int|string $userId): bool
    {
        if (isset($this->superAdminCache[$userId])) {
            return $this->superAdminCache[$userId];
        }

        /** @var string|null $superAdminRole */
        $superAdminRole = config('permissions-redis.super_admin_role');

        if ($superAdminRole === null) {
            $this->superAdminCache[$userId] = false;

            return false;
        }

        $this->ensureUserCacheExists($userId);

        $defaultGuard = $this->resolveGuard(null);

        if ($this->repository->userHasRole($userId, "{$defaultGuard}|{$superAdminRole}")) {
            $this->superAdminCache[$userId] = true;

            return true;
        }

        /** @var array<string, array<string, mixed>> $guards */
        $guards = config('auth.guards', ['web' => []]);

        foreach (array_keys($guards) as $guard) {
            if ($guard === $defaultGuard) {
                continue;
            }

            if ($this->repository->userHasRole($userId, "{$guard}|{$superAdminRole}")) {
                $this->superAdminCache[$userId] = true;

                return true;
            }
        }

        $this->superAdminCache[$userId] = false;

        return false;
    }

    private function resolveGuard(?string $guard): string
    {
        if ($guard !== null) {
            return $guard;
        }

        return auth()->getDefaultDriver();
    }

    private function wildcardEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('permissions-redis.wildcard_permissions', false);

        return $enabled;
    }

    private function matchWildcard(int|string $userId, string $permission, string $guard): bool
    {
        $allPermissions = $this->loadUserPermissions($userId);

        foreach ($allPermissions as $stored) {
            [$storedGuard, $storedName] = $this->decodeEntry($stored);

            if ($storedGuard === $guard && str_contains($storedName, '*') && fnmatch($storedName, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string> */
    private function loadUserPermissions(int|string $userId): array
    {
        if (isset($this->allPermissionsCache[$userId])) {
            return $this->allPermissionsCache[$userId];
        }

        $this->ensureUserCacheExists($userId);

        $names = $this->repository->getUserPermissions($userId);
        $this->allPermissionsCache[$userId] = $names;

        foreach ($names as $encoded) {
            $this->permissionCache[$userId][$encoded] = true;
        }

        return $names;
    }

    /** @return array<string> */
    private function loadUserRoles(int|string $userId): array
    {
        if (isset($this->allRolesCache[$userId])) {
            return $this->allRolesCache[$userId];
        }

        $this->ensureUserCacheExists($userId);

        $roles = $this->repository->getUserRoles($userId);
        $this->allRolesCache[$userId] = $roles;

        foreach ($roles as $encoded) {
            $this->roleCache[$userId][$encoded] = true;
        }

        return $roles;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeEntry(string $encoded): array
    {
        $parts = explode('|', $encoded, 2);

        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return [auth()->getDefaultDriver(), $parts[0]];
    }
}
