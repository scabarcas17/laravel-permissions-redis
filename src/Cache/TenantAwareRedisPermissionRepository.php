<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Closure;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

class TenantAwareRedisPermissionRepository implements PermissionRepositoryInterface
{
    /** @var Closure(): (string|int|null) */
    private Closure $tenantResolver;

    /**
     * @param Closure(): (string|int|null) $tenantResolver
     */
    public function __construct(
        private readonly PermissionRepositoryInterface $inner,
        Closure $tenantResolver,
    ) {
        $this->tenantResolver = $tenantResolver;
    }

    public function userHasPermission(int|string $userId, string $permission): bool
    {
        return $this->inner->userHasPermission($this->tenantKey($userId), $permission);
    }

    public function userHasRole(int|string $userId, string $role): bool
    {
        return $this->inner->userHasRole($this->tenantKey($userId), $role);
    }

    /** @return array<string> */
    public function getUserPermissions(int|string $userId): array
    {
        return $this->inner->getUserPermissions($this->tenantKey($userId));
    }

    /** @return array<string> */
    public function getUserRoles(int|string $userId): array
    {
        return $this->inner->getUserRoles($this->tenantKey($userId));
    }

    /** @return array<int|string> */
    public function getRoleUserIds(int $roleId): array
    {
        return $this->inner->getRoleUserIds($roleId);
    }

    /** @param array<string> $permissions */
    public function setUserPermissions(int|string $userId, array $permissions): void
    {
        $this->inner->setUserPermissions($this->tenantKey($userId), $permissions);
    }

    /** @param array<string> $roles */
    public function setUserRoles(int|string $userId, array $roles): void
    {
        $this->inner->setUserRoles($this->tenantKey($userId), $roles);
    }

    /** @param array<string> $permissions */
    public function setRolePermissions(int $roleId, array $permissions): void
    {
        $this->inner->setRolePermissions($roleId, $permissions);
    }

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int $roleId, array $userIds): void
    {
        $this->inner->setRoleUsers($roleId, $userIds);
    }

    public function userCacheExists(int|string $userId): bool
    {
        return $this->inner->userCacheExists($this->tenantKey($userId));
    }

    public function deleteUserCache(int|string $userId): void
    {
        $this->inner->deleteUserCache($this->tenantKey($userId));
    }

    public function deleteRoleCache(int $roleId): void
    {
        $this->inner->deleteRoleCache($roleId);
    }

    public function flushAll(): void
    {
        $this->inner->flushAll();
    }

    private function tenantKey(int|string $userId): string
    {
        $tenantId = ($this->tenantResolver)();

        if ($tenantId === null) {
            return (string) $userId;
        }

        return "t:{$tenantId}:{$userId}";
    }
}
