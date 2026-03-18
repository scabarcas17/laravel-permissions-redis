<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Cache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

class RedisPermissionRepository implements PermissionRepositoryInterface
{
    public function userHasPermission(int $userId, string $permission): bool
    {
        return (bool) $this->connection()->command('sismember', [
            $this->userPermissionsKey($userId),
            $permission,
        ]);
    }

    public function userHasRole(int $userId, string $role): bool
    {
        return (bool) $this->connection()->command('sismember', [
            $this->userRolesKey($userId),
            $role,
        ]);
    }

    /** @return array<string> */
    public function getUserPermissions(int $userId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->userPermissionsKey($userId),
        ]);

        return $members ?: [];
    }

    /** @return array<string> */
    public function getUserRoles(int $userId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->userRolesKey($userId),
        ]);

        return $members ?: [];
    }

    /** @return array<int> */
    public function getRoleUserIds(int $roleId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->roleUsersKey($roleId),
        ]);

        return array_map('intval', $members ?: []);
    }

    /** @param array<string> $permissions */
    public function setUserPermissions(int $userId, array $permissions): void
    {
        $this->replaceSet($this->userPermissionsKey($userId), $permissions);
    }

    /** @param array<string> $roles */
    public function setUserRoles(int $userId, array $roles): void
    {
        $this->replaceSet($this->userRolesKey($userId), $roles);
    }

    /** @param array<string> $permissions */
    public function setRolePermissions(int $roleId, array $permissions): void
    {
        $this->replaceSet($this->rolePermissionsKey($roleId), $permissions);
    }

    /** @param array<int> $userIds */
    public function setRoleUsers(int $roleId, array $userIds): void
    {
        $stringIds = array_map('strval', $userIds);

        $this->replaceSet($this->roleUsersKey($roleId), $stringIds);
    }

    public function userCacheExists(int $userId): bool
    {
        return (bool) $this->connection()->command('exists', [
            $this->userPermissionsKey($userId),
        ]);
    }

    public function deleteUserCache(int $userId): void
    {
        $this->connection()->command('del', [
            $this->userPermissionsKey($userId),
            $this->userRolesKey($userId),
        ]);
    }

    public function deleteRoleCache(int $roleId): void
    {
        $this->connection()->command('del', [
            $this->rolePermissionsKey($roleId),
            $this->roleUsersKey($roleId),
        ]);
    }

    public function flushAll(): void
    {
        $connection = $this->connection();
        $prefix = $this->prefix();

        /** @var array<string> $keys */
        $keys = $connection->command('keys', [$prefix . '*']);

        if ($keys !== []) {
            $connection->command('del', $keys);
        }
    }

    // ─── Private helpers ───

    /** @param array<string> $members */
    private function replaceSet(string $key, array $members): void
    {
        $connection = $this->connection();
        $ttl = $this->ttl();

        $connection->command('del', [$key]);

        if ($members !== []) {
            $connection->command('sadd', [$key, ...$members]);
        }

        $connection->command('expire', [$key, $ttl]);
    }

    // ─── Key builders ───

    private function userPermissionsKey(int $userId): string
    {
        return $this->prefix() . "user:{$userId}:permissions";
    }

    private function userRolesKey(int $userId): string
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
        /** @var string $connectionName */
        $connectionName = config('permissions-redis.redis_connection', 'default');

        return Redis::connection($connectionName);
    }

    private function prefix(): string
    {
        /** @var string $prefix */
        $prefix = config('permissions-redis.prefix', 'auth:');

        return $prefix;
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('permissions-redis.ttl', 86400);

        return $ttl;
    }
}
