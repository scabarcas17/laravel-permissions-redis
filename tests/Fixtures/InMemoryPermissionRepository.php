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

    /** @var array<int|string, array<string>> */
    private array $rolePermissions = [];

    /** @var array<int|string, array<int|string>> */
    private array $roleUsers = [];

    /** @var array<string, string> */
    private array $permissionGroups = [];

    public function userHasPermission(int|string $userId, string $permission): bool
    {
        return in_array($permission, $this->userPermissions[$userId] ?? [], true);
    }

    public function userHasRole(int|string $userId, string $role): bool
    {
        return in_array($role, $this->userRoles[$userId] ?? [], true);
    }

    public function roleHasPermission(int|string $roleId, string $permission): bool
    {
        return in_array($permission, $this->rolePermissions[$roleId] ?? [], true);
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
    public function getRoleUserIds(int|string $roleId): array
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
    public function setRolePermissions(int|string $roleId, array $permissions): void
    {
        $this->rolePermissions[$roleId] = $permissions;
    }

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int|string $roleId, array $userIds): void
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

    public function deleteRoleCache(int|string $roleId): void
    {
        unset($this->rolePermissions[$roleId], $this->roleUsers[$roleId]);
    }

    public function flushAll(): void
    {
        $this->userPermissions = [];
        $this->userRoles = [];
        $this->rolePermissions = [];
        $this->roleUsers = [];
        $this->permissionGroups = [];
    }

    /** @param array<string, string|null> $groups */
    public function setPermissionGroups(array $groups): void
    {
        foreach ($groups as $encodedName => $group) {
            if ($group === null || $group === '') {
                unset($this->permissionGroups[$encodedName]);
            } else {
                $this->permissionGroups[$encodedName] = $group;
            }
        }
    }

    /**
     * @param array<string> $encodedNames
     *
     * @return array<string, string|null>
     */
    public function getPermissionGroups(array $encodedNames): array
    {
        $result = [];

        foreach ($encodedNames as $encodedName) {
            $result[$encodedName] = $this->permissionGroups[$encodedName] ?? null;
        }

        return $result;
    }

    public function deletePermissionGroup(string $encodedName): void
    {
        unset($this->permissionGroups[$encodedName]);
    }

    /** @param array<string, string|null> $groups */
    public function replacePermissionGroups(array $groups): void
    {
        $this->permissionGroups = [];

        foreach ($groups as $encodedName => $group) {
            if (is_string($group) && $group !== '') {
                $this->permissionGroups[$encodedName] = $group;
            }
        }
    }

    /** @param array<string, array<string>> $sets */
    public function replaceSetBatch(array $sets): void
    {
        foreach ($sets as $keySuffix => $members) {
            if (preg_match('/^user:(.+):permissions$/', $keySuffix, $m)) {
                $this->userPermissions[$m[1]] = $members;
            } elseif (preg_match('/^user:(.+):roles$/', $keySuffix, $m)) {
                $this->userRoles[$m[1]] = $members;
            } elseif (preg_match('/^role:(.+):permissions$/', $keySuffix, $m)) {
                $this->rolePermissions[$m[1]] = $members;
            } elseif (preg_match('/^role:(.+):users$/', $keySuffix, $m)) {
                $this->roleUsers[$m[1]] = array_map(
                    fn (string $id): int|string => ctype_digit($id) ? (int) $id : $id,
                    $members,
                );
            }
        }
    }
}
