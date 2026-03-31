<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

class InMemoryPermissionRepository implements PermissionRepositoryInterface
{
    /** @var array<int|string, array<string>> */
    private array $userPermissions = [];

    /** @var array<int|string, array<string>> */
    private array $userRoles = [];

    /** @var array<int, array<string>> */
    private array $rolePermissions = [];

    /** @var array<int, array<int|string>> */
    private array $roleUsers = [];

    public function userHasPermission(int|string $userId, string $permission): bool
    {
        return in_array($permission, $this->userPermissions[$userId] ?? [], true);
    }

    public function userHasRole(int|string $userId, string $role): bool
    {
        return in_array($role, $this->userRoles[$userId] ?? [], true);
    }

    /** @return array<string> */
    public function getUserPermissions(int|string $userId): array
    {
        return $this->userPermissions[$userId] ?? [];
    }

    /** @return array<string> */
    public function getUserRoles(int|string $userId): array
    {
        return $this->userRoles[$userId] ?? [];
    }

    /** @return array<int|string> */
    public function getRoleUserIds(int $roleId): array
    {
        return $this->roleUsers[$roleId] ?? [];
    }

    /** @param array<string> $permissions */
    public function setUserPermissions(int|string $userId, array $permissions): void
    {
        $this->userPermissions[$userId] = $permissions;
    }

    /** @param array<string> $roles */
    public function setUserRoles(int|string $userId, array $roles): void
    {
        $this->userRoles[$userId] = $roles;
    }

    /** @param array<string> $permissions */
    public function setRolePermissions(int $roleId, array $permissions): void
    {
        $this->rolePermissions[$roleId] = $permissions;
    }

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int $roleId, array $userIds): void
    {
        $this->roleUsers[$roleId] = $userIds;
    }

    public function userCacheExists(int|string $userId): bool
    {
        return isset($this->userPermissions[$userId]);
    }

    public function deleteUserCache(int|string $userId): void
    {
        unset($this->userPermissions[$userId], $this->userRoles[$userId]);
    }

    public function deleteRoleCache(int $roleId): void
    {
        unset($this->rolePermissions[$roleId], $this->roleUsers[$roleId]);
    }

    public function flushAll(): void
    {
        $this->userPermissions = [];
        $this->userRoles = [];
        $this->rolePermissions = [];
        $this->roleUsers = [];
    }
}
