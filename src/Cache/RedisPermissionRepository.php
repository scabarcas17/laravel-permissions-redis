<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Throwable;

class RedisPermissionRepository implements PermissionRepositoryInterface
{
    private ?Connection $cachedConnection = null;

    private ?string $cachedPrefix = null;

    private ?int $cachedTtl = null;

    /**
     * Reset cached state between Octane requests.
     */
    public function resetState(): void
    {
        $this->cachedConnection = null;
        $this->cachedPrefix = null;
        $this->cachedTtl = null;
    }

    /**
     * @throws Throwable
     */
    public function userHasPermission(int|string $userId, string $permission): bool
    {
        return (bool) $this->connection()->command('sismember', [
            $this->userPermissionsKey($userId),
            $permission,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function userHasRole(int|string $userId, string $role): bool
    {
        return (bool) $this->connection()->command('sismember', [
            $this->userRolesKey($userId),
            $role,
        ]);
    }

    /**
     * @throws Throwable
     *
     * @return array<string>
     */
    public function getUserPermissions(int|string $userId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->userPermissionsKey($userId),
        ]);

        return array_values(array_filter($members ?: [], fn (string $m): bool => $m !== '__empty__'));
    }

    /**
     * @throws Throwable
     *
     * @return array<string>
     */
    public function getUserRoles(int|string $userId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->userRolesKey($userId),
        ]);

        return array_values(array_filter($members ?: [], fn (string $m): bool => $m !== '__empty__'));
    }

    /**
     * @throws Throwable
     *
     * @return array<int|string>
     */
    public function getRoleUserIds(int $roleId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->roleUsersKey($roleId),
        ]);

        $filtered = array_filter($members ?: [], fn (string $m): bool => $m !== '__empty__');

        return array_values(array_map(
            fn (string $id): int|string => ctype_digit($id) ? (int) $id : $id,
            $filtered,
        ));
    }

    /** @param array<string> $permissions
     * @throws Throwable
     */
    public function setUserPermissions(int|string $userId, array $permissions): void
    {
        $this->replaceSet($this->userPermissionsKey($userId), $permissions);
    }

    /** @param array<string> $roles
     * @throws Throwable
     */
    public function setUserRoles(int|string $userId, array $roles): void
    {
        $this->replaceSet($this->userRolesKey($userId), $roles);
    }

    /** @param array<string> $permissions
     * @throws Throwable
     */
    public function setRolePermissions(int $roleId, array $permissions): void
    {
        $this->replaceSet($this->rolePermissionsKey($roleId), $permissions);
    }

    /** @param array<int|string> $userIds
     * @throws Throwable
     */
    public function setRoleUsers(int $roleId, array $userIds): void
    {
        $stringIds = array_map('strval', $userIds);

        $this->replaceSet($this->roleUsersKey($roleId), $stringIds);
    }

    /**
     * @throws Throwable
     */
    public function userCacheExists(int|string $userId): bool
    {
        return (bool) $this->connection()->command('exists', [
            $this->userPermissionsKey($userId),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function deleteUserCache(int|string $userId): void
    {
        $this->connection()->command('del', [
            $this->userPermissionsKey($userId),
            $this->userRolesKey($userId),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function deleteRoleCache(int $roleId): void
    {
        $this->connection()->command('del', [
            $this->rolePermissionsKey($roleId),
            $this->roleUsersKey($roleId),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function flushAll(): void
    {
        $connection = $this->connection();
        $prefix = $this->prefix();
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} $result */
            $result = $connection->command('scan', [$cursor, 'match', $prefix . '*', 'count', 100]);
            $cursor = $result[0];
            $keys = $result[1];

            if ($keys !== []) {
                $connection->command('del', $keys);
            }
        } while ($cursor !== '0');
    }

    /** @param array<string> $members
     * @throws Throwable
     */
    private function replaceSet(string $key, array $members): void
    {
        $connection = $this->connection();
        $ttl = $this->ttl();

        $connection->command('multi');
        $connection->command('del', [$key]);

        if ($members !== []) {
            $connection->command('sadd', [$key, ...$members]);
        } else {
            $connection->command('sadd', [$key, '__empty__']);
        }

        $connection->command('expire', [$key, $ttl]);
        $connection->command('exec');
    }

    private function userPermissionsKey(int|string $userId): string
    {
        return $this->prefix() . "user:{$userId}:permissions";
    }

    private function userRolesKey(int|string $userId): string
    {
        return $this->prefix() . "user:{$userId}:roles";
    }

    private function rolePermissionsKey(int $roleId): string
    {
        return $this->prefix() . "role:{$roleId}:permissions";
    }

    private function roleUsersKey(int $roleId): string
    {
        return $this->prefix() . "role:{$roleId}:users";
    }

    private function connection(): Connection
    {
        if ($this->cachedConnection === null) {
            /** @var string $connectionName */
            $connectionName = config('permissions-redis.redis_connection', 'default');
            $this->cachedConnection = Redis::connection($connectionName);
        }

        return $this->cachedConnection;
    }

    private function prefix(): string
    {
        if ($this->cachedPrefix === null) {
            /** @var string $prefix */
            $prefix = config('permissions-redis.prefix', 'auth:');
            $this->cachedPrefix = $prefix;
        }

        return $this->cachedPrefix;
    }

    private function ttl(): int
    {
        if ($this->cachedTtl === null) {
            /** @var int $ttl */
            $ttl = config('permissions-redis.ttl', 86400);
            $this->cachedTtl = $ttl;
        }

        return $this->cachedTtl;
    }
}
