<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Closure;
use Illuminate\Support\Facades\Redis;
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

    public function roleHasPermission(int|string $roleId, string $permission): bool
    {
        return $this->inner->roleHasPermission($this->tenantKey($roleId), $permission);
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
    public function getRoleUserIds(int|string $roleId): array
    {
        return $this->inner->getRoleUserIds($this->tenantKey($roleId));
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
    public function setRolePermissions(int|string $roleId, array $permissions): void
    {
        $this->inner->setRolePermissions($this->tenantKey($roleId), $permissions);
    }

    /** @param array<int|string> $userIds */
    public function setRoleUsers(int|string $roleId, array $userIds): void
    {
        $this->inner->setRoleUsers($this->tenantKey($roleId), $userIds);
    }

    public function userCacheExists(int|string $userId): bool
    {
        return $this->inner->userCacheExists($this->tenantKey($userId));
    }

    public function deleteUserCache(int|string $userId): void
    {
        $this->inner->deleteUserCache($this->tenantKey($userId));
    }

    public function deleteRoleCache(int|string $roleId): void
    {
        $this->inner->deleteRoleCache($this->tenantKey($roleId));
    }

    public function flushAll(): void
    {
        $tenantId = ($this->tenantResolver)();

        if ($tenantId === null) {
            $this->inner->flushAll();

            return;
        }

        /** @var string $connectionName */
        $connectionName = config('permissions-redis.redis_connection', 'default');
        $connection = Redis::connection($connectionName);

        /** @var string $prefix */
        $prefix = config('permissions-redis.prefix', 'auth:');
        $pattern = $prefix . '*:t:' . $tenantId . ':*';
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} $result */
            $result = $connection->command('scan', [$cursor, 'match', $pattern, 'count', 100]);
            $cursor = $result[0];
            $keys = $result[1];

            if ($keys !== []) {
                $connection->command('del', $keys);
            }
        } while ($cursor !== '0');
    }

    /**
     * Permission groups are global (guard|name → group_name) so they are NOT
     * tenant-scoped — they live in the shared permissions table. Delegate
     * directly to the inner repository.
     *
     * @param array<string, string|null> $groups
     */
    public function setPermissionGroups(array $groups): void
    {
        $this->inner->setPermissionGroups($groups);
    }

    /**
     * @param array<string> $encodedNames
     *
     * @return array<string, string|null>
     */
    public function getPermissionGroups(array $encodedNames): array
    {
        return $this->inner->getPermissionGroups($encodedNames);
    }

    public function deletePermissionGroup(string $encodedName): void
    {
        $this->inner->deletePermissionGroup($encodedName);
    }

    /** @param array<string, array<string>> $sets */
    public function replaceSetBatch(array $sets): void
    {
        $tenantId = ($this->tenantResolver)();

        if ($tenantId === null) {
            $this->inner->replaceSetBatch($sets);

            return;
        }

        $prefixed = [];

        foreach ($sets as $keySuffix => $members) {
            // Replace the ID portion of key suffixes with tenant-prefixed ID
            $prefixed[$this->prefixKeySuffix($keySuffix)] = $members;
        }

        $this->inner->replaceSetBatch($prefixed);
    }

    private function prefixKeySuffix(string $keySuffix): string
    {
        // Transforms "user:123:permissions" → "user:t:{tenantId}:123:permissions"
        // and "role:5:users" → "role:t:{tenantId}:5:users"
        return (string) preg_replace_callback(
            '/^(user|role):(.+):(permissions|roles|users)$/',
            fn (array $m): string => $m[1] . ':' . $this->tenantKey($m[2]) . ':' . $m[3],
            $keySuffix,
        );
    }

    private function tenantKey(int|string $id): string
    {
        $tenantId = ($this->tenantResolver)();

        if ($tenantId === null) {
            return (string) $id;
        }

        return "t:{$tenantId}:{$id}";
    }
}
