<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Contracts;

interface PermissionRepositoryInterface
{
    public function userHasPermission(int|string $userId, string $permission): bool;

    public function userHasRole(int|string $userId, string $role): bool;

    public function roleHasPermission(int|string $roleId, string $permission): bool;

    /** @return array<string> */
    public function getUserPermissions(int|string $userId): array;

    /** @return array<string> */
    public function getUserRoles(int|string $userId): array;

    /** @return array<int|string> */
    public function getRoleUserIds(int|string $roleId): array;

    /** @param array<string> $permissions */
    public function setUserPermissions(int|string $userId, array $permissions): void;

    /** @param array<string> $roles */
    public function setUserRoles(int|string $userId, array $roles): void;

    /** @param array<string> $permissions */
    public function setRolePermissions(int|string $roleId, array $permissions): void;

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int|string $roleId, array $userIds): void;

    public function userCacheExists(int|string $userId): bool;

    public function deleteUserCache(int|string $userId): void;

    public function deleteRoleCache(int|string $roleId): void;

    public function flushAll(): void;

    /**
     * Replace multiple sets at once. Implementations may use pipelines
     * or transactions to reduce round-trips.
     *
     * @param array<string, array<string>> $sets Map of Redis key suffix => members
     *                                           Key suffixes: "user:{id}:permissions", "user:{id}:roles", etc.
     */
    public function replaceSetBatch(array $sets): void;

    /**
     * Bulk-set permission group metadata. Keys are encoded permission names
     * ("{guard}|{name}"); values are the group name or null for "no group".
     * Permission groups are global (not tenant-scoped) because they live in
     * the shared permissions table.
     *
     * @param array<string, string|null> $groups
     */
    public function setPermissionGroups(array $groups): void;

    /**
     * Batch-fetch permission group metadata by encoded permission name.
     * Unknown keys return null.
     *
     * @param array<string> $encodedNames
     *
     * @return array<string, string|null>
     */
    public function getPermissionGroups(array $encodedNames): array;

    /**
     * Remove a single permission's group metadata from the Redis hash. Called
     * when a Permission row is deleted.
     */
    public function deletePermissionGroup(string $encodedName): void;
}
