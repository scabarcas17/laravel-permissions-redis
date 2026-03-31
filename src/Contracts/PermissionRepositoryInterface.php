<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Contracts;

interface PermissionRepositoryInterface
{
    public function userHasPermission(int|string $userId, string $permission): bool;

    public function userHasRole(int|string $userId, string $role): bool;

    /** @return array<string> */
    public function getUserPermissions(int|string $userId): array;

    /** @return array<string> */
    public function getUserRoles(int|string $userId): array;

    /** @return array<int|string> */
    public function getRoleUserIds(int $roleId): array;

    /** @param array<string> $permissions */
    public function setUserPermissions(int|string $userId, array $permissions): void;

    /** @param array<string> $roles */
    public function setUserRoles(int|string $userId, array $roles): void;

    /** @param array<string> $permissions */
    public function setRolePermissions(int $roleId, array $permissions): void;

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int $roleId, array $userIds): void;

    public function userCacheExists(int|string $userId): bool;

    public function deleteUserCache(int|string $userId): void;

    public function deleteRoleCache(int $roleId): void;

    public function flushAll(): void;
}
