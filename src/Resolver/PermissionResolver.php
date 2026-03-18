<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Resolver;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Sebastian\LaravelPermissionsRedis\DTO\PermissionDTO;

class PermissionResolver implements PermissionResolverInterface
{
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
        if (isset($this->permissionCache[$userId][$permission])) {
            return $this->permissionCache[$userId][$permission];
        }

        $this->ensureUserCacheExists($userId);

        $result = $this->repository->userHasPermission($userId, $permission);

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
            $this->log("Auth cache miss for user {$userId}, warming from database.");
            $this->cacheManager->warmUser($userId);
        }
    }

    private function log(string $message): void
    {
        /** @var string|null $channel */
        $channel = config('permissions-redis.log_channel');

        if ($channel !== null) {
            Log::channel($channel)->warning($message);
        } else {
            Log::warning("[permissions-redis] {$message}");
        }
    }
}
