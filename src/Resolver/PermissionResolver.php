<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Resolver;

use Illuminate\Support\Collection;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\LogsMessages;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\ReadsUserSets;
use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;
use Throwable;

class PermissionResolver implements PermissionResolverInterface
{
    use LogsMessages;

    /** @var array<int|string, array<string, bool>> */
    private array $permissionCache = [];

    /** @var array<int|string, array<string, bool>> */
    private array $roleCache = [];

    /** @var array<int|string, array<array-key, true>> each user's encoded permissions, as keys */
    private array $allPermissionsCache = [];

    /** @var array<int|string, array<array-key, true>> each user's encoded roles, as keys */
    private array $allRolesCache = [];

    /** @var array<int|string, bool> */
    private array $superAdminCache = [];

    /** @var array<int|string, float> */
    private array $warmAttempts = [];

    /**
     * Users whose Redis entry is known to exist, so a process asks Redis
     * once per user instead of before every check.
     *
     * @var array<int|string, true>
     */
    private array $verifiedUsers = [];

    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
        private readonly AuthorizationCacheManager $cacheManager,
    ) {
    }

    public function hasPermission(int|string $userId, string $permission, ?string $guard = null): bool
    {
        $guard = $this->resolveGuard($guard);
        $cacheKey = "{$guard}|{$permission}";

        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        if (isset($this->permissionCache[$userId][$cacheKey])) {
            return $this->permissionCache[$userId][$cacheKey];
        }

        // The first check reads the user's sets and the later ones look them
        // up in memory, rather than one SISMEMBER round trip per distinct
        // check.
        $permissions = $this->loadUserPermissions($userId);

        $result = isset($permissions[$cacheKey]);

        if (!$result && $this->wildcardEnabled()) {
            $result = $this->matchWildcard($permissions, $permission, $guard);
        }

        // Kept only when it came from the user's set: an answer given while
        // the entry was missing is worked out again next time.
        if (isset($this->allPermissionsCache[$userId])) {
            $this->permissionCache[$userId][$cacheKey] = $result;
        }

        $this->evictIfNeeded();

        return $result;
    }

    public function hasRole(int|string $userId, string $role, ?string $guard = null): bool
    {
        $guard = $this->resolveGuard($guard);
        $cacheKey = "{$guard}|{$role}";

        if (isset($this->roleCache[$userId][$cacheKey])) {
            return $this->roleCache[$userId][$cacheKey];
        }

        $roles = $this->loadUserRoles($userId);
        $result = isset($roles[$cacheKey]);

        if (isset($this->allRolesCache[$userId])) {
            $this->roleCache[$userId][$cacheKey] = $result;
        }

        $this->evictIfNeeded();

        return $result;
    }

    /** @return Collection<int, PermissionDTO> */
    public function getAllPermissions(int|string $userId, ?string $guard = null): Collection
    {
        $names = $this->encodedNames($this->loadUserPermissions($userId));

        if ($names === []) {
            return new Collection();
        }

        $groups = $this->repository->getPermissionGroups($names);

        /** @var array<int, PermissionDTO> $dtos */
        $dtos = [];

        foreach ($names as $encoded) {
            [$guardName, $permissionName] = $this->decodeEntry($encoded);

            if ($guard !== null && $guardName !== $guard) {
                continue;
            }

            $dtos[] = new PermissionDTO(
                name: $permissionName,
                group: $groups[$encoded] ?? null,
                guard: $guardName,
            );
        }

        return new Collection($dtos);
    }

    /** @return Collection<int, string> */
    public function getAllRoles(int|string $userId, ?string $guard = null): Collection
    {
        $roles = $this->encodedNames($this->loadUserRoles($userId));

        return collect($roles)
            ->map(fn (string $encoded): array => $this->decodeEntry($encoded))
            ->when($guard !== null, fn (Collection $c) => $c->filter(fn (array $entry): bool => $entry[0] === $guard))
            ->map(fn (array $entry): string => $entry[1])
            ->values();
    }

    public function flush(): void
    {
        $this->permissionCache = [];
        $this->roleCache = [];
        $this->allPermissionsCache = [];
        $this->allRolesCache = [];
        $this->superAdminCache = [];
        $this->warmAttempts = [];
        $this->verifiedUsers = [];
    }

    public function flushUser(int|string $userId): void
    {
        unset(
            $this->permissionCache[$userId],
            $this->roleCache[$userId],
            $this->allPermissionsCache[$userId],
            $this->allRolesCache[$userId],
            $this->superAdminCache[$userId],
            $this->warmAttempts[$userId],
            $this->verifiedUsers[$userId],
        );
    }

    /**
     * Keeps every per-user map (including the warm cooldown timestamps,
     * which only grow in a long-running worker) at or below the configured
     * limit, dropping the oldest entries of each one independently.
     */
    private function evictIfNeeded(): void
    {
        /** @var int $limit */
        $limit = config('permissions-redis.resolver_cache_limit', 1000);

        $this->permissionCache = $this->trimToLimit($this->permissionCache, $limit);
        $this->roleCache = $this->trimToLimit($this->roleCache, $limit);
        $this->allPermissionsCache = $this->trimToLimit($this->allPermissionsCache, $limit);
        $this->allRolesCache = $this->trimToLimit($this->allRolesCache, $limit);
        $this->superAdminCache = $this->trimToLimit($this->superAdminCache, $limit);
        $this->warmAttempts = $this->trimToLimit($this->warmAttempts, $limit);
        $this->verifiedUsers = $this->trimToLimit($this->verifiedUsers, $limit);
    }

    /**
     * @template TValue
     *
     * @param array<int|string, TValue> $cache
     *
     * @return array<int|string, TValue>
     */
    private function trimToLimit(array $cache, int $limit): array
    {
        $overflow = count($cache) - $limit;

        if ($overflow <= 0) {
            return $cache;
        }

        return array_slice($cache, $overflow, null, true);
    }

    /**
     * Whether the user's Redis entry exists, warming it from the database on
     * a miss. A miss inside the warm cooldown answers false without warming.
     */
    private function ensureUserCacheExists(int|string $userId): bool
    {
        if (isset($this->verifiedUsers[$userId])) {
            return true;
        }

        $exists = $this->repository->userCacheExists($userId)
            || ($this->warmMissingUser($userId) && $this->repository->userCacheExists($userId));

        if (!$exists) {
            return false;
        }

        $this->verifiedUsers[$userId] = true;

        return true;
    }

    /**
     * Warms a user whose Redis entry is missing; false, without warming,
     * inside the warm cooldown.
     */
    private function warmMissingUser(int|string $userId): bool
    {
        $cooldown = $this->warmCooldownSeconds();

        if ($cooldown > 0.0 && isset($this->warmAttempts[$userId])) {
            $elapsed = microtime(true) - $this->warmAttempts[$userId];

            if ($elapsed < $cooldown) {
                return false;
            }
        }

        $this->log("Auth cache miss for user {$userId}, warming from database.", 'debug');

        try {
            $this->cacheManager->warmUser($userId);
        } finally {
            // Stamped after the call, failed or not: warmUser() drops this
            // user's in-memory state, an earlier stamp included.
            $this->warmAttempts[$userId] = microtime(true);
        }

        return true;
    }

    private function warmCooldownSeconds(): float
    {
        /** @var float|int $cooldown */
        $cooldown = config('permissions-redis.resolver_warm_cooldown', 1.0);

        return (float) $cooldown;
    }

    private function isSuperAdmin(int|string $userId): bool
    {
        if (isset($this->superAdminCache[$userId])) {
            return $this->superAdminCache[$userId];
        }

        /** @var string|null $superAdminRole */
        $superAdminRole = config('permissions-redis.super_admin_role');

        if ($superAdminRole === null) {
            $this->superAdminCache[$userId] = false;

            return false;
        }

        // Single SMEMBERS call instead of one SISMEMBER per guard
        $roles = $this->encodedNames($this->loadUserRoles($userId));

        foreach ($roles as $encoded) {
            [, $name] = $this->decodeEntry($encoded);

            if ($name === $superAdminRole) {
                $this->superAdminCache[$userId] = true;

                return true;
            }
        }

        if (isset($this->allRolesCache[$userId])) {
            $this->superAdminCache[$userId] = false;
        }

        return false;
    }

    private function resolveGuard(?string $guard): string
    {
        if ($guard !== null) {
            return $guard;
        }

        return $this->defaultGuard();
    }

    private function defaultGuard(): string
    {
        try {
            $driver = auth()->getDefaultDriver();

            if ($driver !== '') {
                return $driver;
            }
        } catch (Throwable) {
            // Queue/console contexts may not have an auth manager bound.
        }

        /** @var string $fallback */
        $fallback = config('auth.defaults.guard', 'web');

        return $fallback;
    }

    private function wildcardEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('permissions-redis.wildcard_permissions', false);

        return $enabled;
    }

    /** @param array<array-key, true> $permissions the user's encoded permissions, as keys */
    private function matchWildcard(array $permissions, string $permission, string $guard): bool
    {
        foreach ($this->encodedNames($permissions) as $stored) {
            [$storedGuard, $storedName] = $this->decodeEntry($stored);

            if ($storedGuard === $guard && str_contains($storedName, '*') && fnmatch($storedName, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<array-key, true> the user's encoded permissions, as keys */
    private function loadUserPermissions(int|string $userId): array
    {
        if (isset($this->allPermissionsCache[$userId])) {
            return $this->allPermissionsCache[$userId];
        }

        if ($this->repository instanceof ReadsUserSets) {
            return $this->loadUserSets($this->repository, $userId)['permissions'] ?? [];
        }

        if (!$this->ensureUserCacheExists($userId)) {
            // Missing and cooling down: nothing to read, and nothing kept,
            // so a later call looks again.
            return [];
        }

        $permissions = array_fill_keys($this->repository->getUserPermissions($userId), true);
        $this->allPermissionsCache[$userId] = $permissions;

        $this->evictIfNeeded();

        return $permissions;
    }

    /** @return array<array-key, true> the user's encoded roles, as keys */
    private function loadUserRoles(int|string $userId): array
    {
        if (isset($this->allRolesCache[$userId])) {
            return $this->allRolesCache[$userId];
        }

        if ($this->repository instanceof ReadsUserSets) {
            return $this->loadUserSets($this->repository, $userId)['roles'] ?? [];
        }

        if (!$this->ensureUserCacheExists($userId)) {
            return [];
        }

        $roles = array_fill_keys($this->repository->getUserRoles($userId), true);
        $this->allRolesCache[$userId] = $roles;

        $this->evictIfNeeded();

        return $roles;
    }

    /**
     * Both of the user's sets in one read, kept for the process. Null when
     * the entry is missing and the warm cooldown holds off another warm;
     * nothing is kept then, so a later call reads again.
     *
     * @return array{permissions: array<array-key, true>, roles: array<array-key, true>}|null
     */
    private function loadUserSets(ReadsUserSets $repository, int|string $userId): ?array
    {
        $sets = $repository->getUserSets($userId);

        if ($sets === null && $this->warmMissingUser($userId)) {
            $sets = $repository->getUserSets($userId);
        }

        if ($sets === null) {
            return null;
        }

        $loaded = [
            'permissions' => array_fill_keys($sets['permissions'], true),
            'roles'       => array_fill_keys($sets['roles'], true),
        ];

        $this->allPermissionsCache[$userId] = $loaded['permissions'];
        $this->allRolesCache[$userId] = $loaded['roles'];
        $this->evictIfNeeded();

        return $loaded;
    }

    /**
     * @param array<array-key, true> $set
     *
     * @return array<int, string>
     */
    private function encodedNames(array $set): array
    {
        // PHP stores integer-like string keys as ints; the entries are strings.
        return array_map(fn (int|string $name): string => (string) $name, array_keys($set));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeEntry(string $encoded): array
    {
        $parts = explode('|', $encoded, 2);

        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return [$this->defaultGuard(), $parts[0]];
    }
}
