<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\ReadsUserSets;
use Scabarcas\LaravelPermissionsRedis\Exceptions\TransactionFailedException;
use Throwable;

class RedisPermissionRepository implements PermissionRepositoryInterface, ReadsUserSets
{
    use ScansRedisByPrefix;

    /**
     * Sentinel value stored in empty Redis sets so that key existence
     * reflects "cache populated" rather than "no data". The null bytes make
     * collisions with real permission/role names effectively impossible.
     */
    public const EMPTY_SENTINEL = "\0__lpr_empty__\0";

    /**
     * Both of a user's sets in one command. SMEMBERS of a missing key is an
     * empty list, and a warmed set always holds at least the sentinel.
     */
    private const READ_USER_SETS_SCRIPT = "return {redis.call('SMEMBERS', KEYS[1]), redis.call('SMEMBERS', KEYS[2])}";

    private bool $scriptsUnavailable = false;

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
        $this->scriptsUnavailable = false;
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
     */
    public function roleHasPermission(int|string $roleId, string $permission): bool
    {
        return (bool) $this->connection()->command('sismember', [
            $this->rolePermissionsKey($roleId),
            $permission,
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

        return array_values(array_filter($members ?: [], fn (string $m): bool => $m !== self::EMPTY_SENTINEL));
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

        return array_values(array_filter($members ?: [], fn (string $m): bool => $m !== self::EMPTY_SENTINEL));
    }

    /**
     * @throws Throwable
     *
     * @return array<int|string>
     */
    public function getRoleUserIds(int|string $roleId): array
    {
        /** @var array<string> $members */
        $members = $this->connection()->command('smembers', [
            $this->roleUsersKey($roleId),
        ]);

        $filtered = array_filter($members ?: [], fn (string $m): bool => $m !== self::EMPTY_SENTINEL);

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
    public function setRolePermissions(int|string $roleId, array $permissions): void
    {
        $this->replaceSet($this->rolePermissionsKey($roleId), $permissions);
    }

    /** @param array<int|string> $userIds
     * @throws Throwable
     */
    public function setRoleUsers(int|string $roleId, array $userIds): void
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
    public function deleteRoleCache(int|string $roleId): void
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

        $this->scanByPattern($connection, $this->prefix() . '*', function (array $keys) use ($connection): void {
            $connection->command('del', $keys);
        });
    }

    /**
     * @param array<string, string|null> $groups
     *
     * @throws Throwable
     */
    public function setPermissionGroups(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $connection = $this->connection();
        $key = $this->permissionGroupsKey();

        $setValues = [];
        $deleteFields = [];

        foreach ($groups as $encodedName => $group) {
            if ($group === null || $group === '') {
                $deleteFields[] = $encodedName;
            } else {
                $setValues[$encodedName] = $group;
            }
        }

        $connection->command('multi');

        if ($setValues !== []) {
            // HMSET is deprecated; HSET accepts multi-field since Redis 4.0.
            // Flatten field/value pairs so predis can serialize them.
            $connection->command('hset', [$key, ...$this->flattenHashPairs($setValues)]);
        }

        if ($deleteFields !== []) {
            $connection->command('hdel', [$key, ...$deleteFields]);
        }

        $result = $connection->command('exec');

        if (!is_array($result)) {
            throw TransactionFailedException::forKey($key);
        }
    }

    /**
     * @param array<string> $encodedNames
     *
     * @throws Throwable
     *
     * @return array<string, string|null>
     */
    public function getPermissionGroups(array $encodedNames): array
    {
        if ($encodedNames === []) {
            return [];
        }

        $connection = $this->connection();
        $key = $this->permissionGroupsKey();

        // Deduplicate so the positional mapping below holds for phpredis,
        // whose reply is keyed by field name and collapses repeated fields.
        $fields = array_values(array_unique($encodedNames));

        // The field list must travel as ONE array argument: phpredis exposes
        // hMGet(string $key, array $fields) and rejects spread fields with a
        // TypeError / ArgumentCountError, while predis flattens the array itself.
        /** @var array<int|string, string|false|null>|false $values */
        $values = $connection->command('hmget', [$key, $fields]);

        // predis answers with a positional list, phpredis with a map keyed by
        // field (false for missing fields). Both preserve the requested order.
        $values = is_array($values) ? array_values($values) : [];

        $result = [];

        foreach ($fields as $index => $encodedName) {
            $raw = $values[$index] ?? null;
            $result[$encodedName] = is_string($raw) && $raw !== '' ? $raw : null;
        }

        return $result;
    }

    /**
     * @throws Throwable
     */
    public function deletePermissionGroup(string $encodedName): void
    {
        $this->connection()->command('hdel', [$this->permissionGroupsKey(), $encodedName]);
    }

    /**
     * @param array<string, string|null> $groups
     *
     * @throws Throwable
     */
    public function replacePermissionGroups(array $groups): void
    {
        $connection = $this->connection();
        $key = $this->permissionGroupsKey();

        $setValues = [];

        foreach ($groups as $encodedName => $group) {
            if (is_string($group) && $group !== '') {
                $setValues[$encodedName] = $group;
            }
        }

        $connection->command('multi');
        $connection->command('del', [$key]);

        if ($setValues !== []) {
            $connection->command('hset', [$key, ...$this->flattenHashPairs($setValues)]);
        }

        $result = $connection->command('exec');

        if (!is_array($result)) {
            throw TransactionFailedException::forKey($key);
        }
    }

    /**
     * @param array<string, array<string>> $sets
     *
     * @throws Throwable
     */
    public function replaceSetBatch(array $sets): void
    {
        if ($sets === []) {
            return;
        }

        $ttl = $this->ttl();
        $prefix = $this->prefix();
        $connection = $this->connection();

        $connection->command('multi');

        foreach ($sets as $keySuffix => $members) {
            $key = $prefix . $keySuffix;
            $connection->command('del', [$key]);

            if ($members !== []) {
                $connection->command('sadd', [$key, ...$members]);
            } else {
                $connection->command('sadd', [$key, self::EMPTY_SENTINEL]);
            }

            $connection->command('expire', [$key, $ttl]);
        }

        $result = $connection->command('exec');

        if (!is_array($result)) {
            throw TransactionFailedException::forBatch(count($sets));
        }
    }

    /**
     * @throws Throwable
     *
     * @return array{permissions: array<string>, roles: array<string>}|null
     */
    public function getUserSets(int|string $userId): ?array
    {
        [$permissions, $roles] = $this->readUserSetMembers($userId);

        // A warmed permissions set is never empty (the sentinel stands in for
        // "no permissions"), so an empty one means the entry is missing.
        if ($permissions === []) {
            return null;
        }

        return [
            'permissions' => $this->withoutSentinel($permissions),
            'roles'       => $this->withoutSentinel($roles),
        ];
    }

    /**
     * @param array<string, string> $pairs
     *
     * @return array<int, string>
     */
    private function flattenHashPairs(array $pairs): array
    {
        $flat = [];

        foreach ($pairs as $field => $value) {
            $flat[] = (string) $field;
            $flat[] = $value;
        }

        return $flat;
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
            $connection->command('sadd', [$key, self::EMPTY_SENTINEL]);
        }

        $connection->command('expire', [$key, $ttl]);

        $result = $connection->command('exec');

        if (!is_array($result)) {
            throw TransactionFailedException::forKey($key);
        }
    }

    /**
     * One EVAL when the server runs scripts, two SMEMBERS when it refuses
     * them (an ACL without @scripting, some proxies). A refusal is kept until
     * resetState(), which Octane and queue workers call per request and job.
     *
     * @throws Throwable
     *
     * @return array{0: array<string>, 1: array<string>}
     */
    private function readUserSetMembers(int|string $userId): array
    {
        $connection = $this->connection();
        $permissionsKey = $this->userPermissionsKey($userId);
        $rolesKey = $this->userRolesKey($userId);

        if (!$this->scriptsUnavailable) {
            // phpredis takes (script, keys, key count); predis (script, key count, ...keys).
            $arguments = class_exists(PhpRedis::class) && $connection->client() instanceof PhpRedis
                ? [self::READ_USER_SETS_SCRIPT, [$permissionsKey, $rolesKey], 2]
                : [self::READ_USER_SETS_SCRIPT, 2, $permissionsKey, $rolesKey];

            try {
                // phpredis answers a refused script with false, predis throws.
                $result = $connection->command('eval', $arguments);
            } catch (Throwable) {
                $result = null;
            }

            if (is_array($result) && is_array($result[0] ?? null) && is_array($result[1] ?? null)) {
                return [$this->stringMembers($result[0]), $this->stringMembers($result[1])];
            }

            $this->scriptsUnavailable = true;
        }

        return [
            $this->stringMembers($connection->command('smembers', [$permissionsKey])),
            $this->stringMembers($connection->command('smembers', [$rolesKey])),
        ];
    }

    /**
     * @return array<string>
     */
    private function stringMembers(mixed $members): array
    {
        return is_array($members) ? array_values(array_filter($members, is_string(...))) : [];
    }

    /**
     * @param array<string> $members
     *
     * @return array<string>
     */
    private function withoutSentinel(array $members): array
    {
        return array_values(array_filter($members, fn (string $m): bool => $m !== self::EMPTY_SENTINEL));
    }

    private function userPermissionsKey(int|string $userId): string
    {
        return $this->prefix() . "user:{$userId}:permissions";
    }

    private function userRolesKey(int|string $userId): string
    {
        return $this->prefix() . "user:{$userId}:roles";
    }

    private function rolePermissionsKey(int|string $roleId): string
    {
        return $this->prefix() . "role:{$roleId}:permissions";
    }

    private function roleUsersKey(int|string $roleId): string
    {
        return $this->prefix() . "role:{$roleId}:users";
    }

    private function permissionGroupsKey(): string
    {
        return $this->prefix() . 'permission_groups';
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
