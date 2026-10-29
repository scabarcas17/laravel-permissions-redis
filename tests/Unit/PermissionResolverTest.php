<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;

beforeEach(function () {
    $this->repository = Mockery::mock(PermissionRepositoryInterface::class);
    $this->cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $this->resolver = new PermissionResolver($this->repository, $this->cacheManager);
});

// ─── hasPermission ───

test('hasPermission returns true when user has permission in Redis', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('hasPermission returns false when user lacks permission', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    expect($this->resolver->hasPermission(1, 'users.delete'))->toBeFalse();
});

test('hasPermission uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    $this->resolver->hasPermission(1, 'users.create');
    $result = $this->resolver->hasPermission(1, 'users.create');

    expect($result)->toBeTrue();
});

test('hasPermission reads the user permissions once for every check', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create', 'web|users.edit']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue()
        ->and($this->resolver->hasPermission(1, 'users.edit'))->toBeTrue()
        ->and($this->resolver->hasPermission(1, 'users.delete'))->toBeFalse()
        ->and($this->resolver->hasPermission(1, 'posts.view'))->toBeFalse();
});

test('hasPermission warms cache when Redis cache is empty', function () {
    // Missing before the warm, there after it.
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(false, true);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('hasPermission respects explicit guard parameter', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['api|users.create']);

    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeTrue();
});

test('hasPermission isolates permissions by guard', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    expect($this->resolver->hasPermission(1, 'users.create', 'web'))->toBeTrue();
    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeFalse();
});

// ─── hasRole ───

test('hasRole returns true when user has role in Redis', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('hasRole returns false when user lacks role', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|editor']);

    expect($this->resolver->hasRole(1, 'admin'))->toBeFalse();
});

test('hasRole uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    $this->resolver->hasRole(1, 'admin');
    $result = $this->resolver->hasRole(1, 'admin');

    expect($result)->toBeTrue();
});

test('hasRole warms cache when Redis cache is empty', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(false, true);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('hasRole respects explicit guard parameter', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    expect($this->resolver->hasRole(1, 'admin', 'api'))->toBeFalse();
});

// ─── round trips ───

test('a process asks Redis once whether the user exists and reads each set once', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|posts.view', 'web|posts.edit']);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|editor']);
    $this->repository->shouldReceive('getPermissionGroups')->once()->andReturn([]);

    foreach (['posts.view', 'posts.edit', 'posts.delete', 'users.view'] as $permission) {
        $this->resolver->hasPermission(1, $permission);
    }

    foreach (['admin', 'editor', 'viewer'] as $role) {
        $this->resolver->hasRole(1, $role);
    }

    expect($this->resolver->getAllPermissions(1))->toHaveCount(2)
        ->and($this->resolver->getAllRoles(1)->all())->toBe(['editor']);
});

// ─── getAllPermissions ───

test('getAllPermissions returns collection of PermissionDTO', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create', 'web|users.edit']);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create', 'web|users.edit'])
        ->once()
        ->andReturn(['web|users.create' => null, 'web|users.edit' => null]);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(2)
        ->and($permissions->first())->toBeInstanceOf(PermissionDTO::class)
        ->and($permissions->first()->name)->toBe('users.create')
        ->and($permissions->first()->guard)->toBe('web')
        ->and($permissions->last()->name)->toBe('users.edit');
});

test('getAllPermissions answers later permission checks from memory', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create'])
        ->once()
        ->andReturn(['web|users.create' => null]);

    $this->resolver->getAllPermissions(1);

    // Second call to hasPermission should not hit Redis
    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('getAllPermissions uses in-memory cache for user permission names across calls', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    // User permission names are cached, so getUserPermissions is hit once.
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);
    // Group metadata is not cached per-user, refetched every call so cross-user group changes are visible.
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create'])
        ->twice()
        ->andReturn(['web|users.create' => null]);

    $this->resolver->getAllPermissions(1);
    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(1);
});

test('getAllPermissions filters by guard when specified', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn([
        'web|users.create',
        'api|users.create',
        'web|users.edit',
    ]);
    $this->repository->shouldReceive('getPermissionGroups')
        ->twice()
        ->andReturn([
            'web|users.create' => null,
            'api|users.create' => null,
            'web|users.edit'   => null,
        ]);

    $webPermissions = $this->resolver->getAllPermissions(1, 'web');
    expect($webPermissions)->toHaveCount(2);

    $apiPermissions = $this->resolver->getAllPermissions(1, 'api');
    expect($apiPermissions)->toHaveCount(1)
        ->and($apiPermissions->first()->name)->toBe('users.create')
        ->and($apiPermissions->first()->guard)->toBe('api');
});

test('getAllPermissions enriches DTOs with group metadata from Redis hash', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn([
        'web|users.create',
        'web|posts.edit',
    ]);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create', 'web|posts.edit'])
        ->once()
        ->andReturn([
            'web|users.create' => 'User Management',
            'web|posts.edit'   => 'Content',
        ]);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(2)
        ->and($permissions->first()->group)->toBe('User Management')
        ->and($permissions->last()->group)->toBe('Content');
});

test('getAllPermissions returns null group when permission has no group', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create'])
        ->once()
        ->andReturn(['web|users.create' => null]);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions->first()->group)->toBeNull();
});

// ─── getAllRoles ───

test('getAllRoles returns collection of role names', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin', 'web|editor']);

    $roles = $this->resolver->getAllRoles(1);

    expect($roles)->toHaveCount(2)
        ->and($roles->first())->toBe('admin')
        ->and($roles->last())->toBe('editor');
});

test('getAllRoles answers later role checks from memory', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    $this->resolver->getAllRoles(1);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('getAllRoles filters by guard when specified', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin', 'api|admin', 'web|editor']);

    $webRoles = $this->resolver->getAllRoles(1, 'web');
    expect($webRoles)->toHaveCount(2)->and($webRoles->values()->all())->toBe(['admin', 'editor']);

    $apiRoles = $this->resolver->getAllRoles(1, 'api');
    expect($apiRoles)->toHaveCount(1)->and($apiRoles->first())->toBe('admin');
});

// ─── flush ───

test('flush clears all in-memory caches', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->twice()->andReturn(['web|users.create']);

    $this->resolver->hasPermission(1, 'users.create');
    $this->resolver->flush();
    $this->resolver->hasPermission(1, 'users.create');
});

// ─── super admin ───

test('super admin bypasses all permission checks', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|super-admin']);

    expect($this->resolver->hasPermission(1, 'anything'))->toBeTrue();
});

test('non super admin falls through to normal check', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    // One existence check covers both reads: the roles (for the super admin
    // test) and then the permissions.
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|editor']);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|posts.view']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeFalse();
});

test('super admin bypasses across all guards', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'token', 'provider' => 'users'],
    ]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    // Super admin role exists only in web guard, so a single SMEMBERS call finds it
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|super-admin']);

    // But check passes even for api guard
    expect($this->resolver->hasPermission(1, 'anything', 'api'))->toBeTrue();
});

// ─── wildcard permissions ───

test('wildcard permission matches with fnmatch', function () {
    config()->set('permissions-redis.wildcard_permissions', true);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.*', 'web|posts.view']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('wildcard does not match when disabled', function () {
    config()->set('permissions-redis.wildcard_permissions', false);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.*']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeFalse();
});

test('super admin result is cached in memory', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|super-admin']);

    // First call loads roles from Redis, second uses superAdminCache
    expect($this->resolver->hasPermission(1, 'anything'))->toBeTrue();
    expect($this->resolver->hasPermission(1, 'something_else'))->toBeTrue();
});

test('legacy bare value decoded with default guard fallback', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    // Repository returns legacy non-guard-encoded values
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['users.create', 'users.edit']);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['users.create', 'users.edit'])
        ->once()
        ->andReturn(['users.create' => null, 'users.edit' => null]);

    $permissions = $this->resolver->getAllPermissions(1);

    // Should decode using default guard
    expect($permissions)->toHaveCount(2)
        ->and($permissions->first()->name)->toBe('users.create')
        ->and($permissions->first()->guard)->toBe('web');
});

test('wildcard does not match across guards', function () {
    config()->set('permissions-redis.wildcard_permissions', true);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.*']);

    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeFalse();
});

test('wildcard matches only the wildcard in the requested guard when both guards have wildcards', function () {
    config()->set('permissions-redis.wildcard_permissions', true);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn([
        'web|users.*',
        'api|users.*',
    ]);

    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeTrue();
});

// ─── flush ───

test('in-memory cache evicts oldest entries when limit is exceeded', function () {
    config()->set('permissions-redis.resolver_cache_limit', 3);

    // Populate cache for users 1-4 (exceeding limit of 3)
    for ($i = 1; $i <= 4; $i++) {
        $this->repository->shouldReceive('userCacheExists')->with($i)->andReturn(true);
        $this->repository->shouldReceive('getUserPermissions')->with($i)->andReturn(['web|x']);
    }

    // Fill cache: users 1, 2, 3
    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');
    $this->resolver->hasPermission(3, 'x');

    // Adding user 4 should trigger eviction (limit=3, evicts oldest half=1)
    $this->resolver->hasPermission(4, 'x');

    // User 2 should still be in cache (only user 1 was evicted)
    // User 1 should need to re-fetch from Redis
    // Since we used andReturn, both calls succeed, and the key assertion is that
    // the resolver doesn't crash and functions correctly under eviction
    expect($this->resolver->hasPermission(2, 'x'))->toBeTrue()
        ->and($this->resolver->hasPermission(4, 'x'))->toBeTrue();
});

test('flushUser clears only specific user cache', function () {
    // Populate cache for user 1 and 2
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userCacheExists')->with(2)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->twice()->andReturn(['web|x']);
    $this->repository->shouldReceive('getUserPermissions')->with(2)->once()->andReturn(['web|x']);

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');

    $this->resolver->flushUser(1);

    // User 1 should hit Redis again, user 2 should use memory
    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');
});

// ─── warm cooldown (rate limiting) ───

test('repeated cache misses within cooldown only trigger one warm call', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    // Different permissions each call so the resolver's in-memory permissionCache
    // does not short-circuit and each call reaches ensureUserCacheExists. The
    // warm leaves the entry missing, so nothing is kept: two checks around the
    // warm, then one per permission.
    $this->repository->shouldReceive('userCacheExists')->with(1)->times(4)->andReturn(false);

    // But warm is only called once due to cooldown.
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();

    $this->resolver->hasPermission(1, 'a');
    $this->resolver->hasPermission(1, 'b');
    $this->resolver->hasPermission(1, 'c');
});

test('warm cooldown is per-user (different users are not rate-limited against each other)', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(false);
    $this->repository->shouldReceive('userCacheExists')->with(2)->twice()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->cacheManager->shouldReceive('warmUser')->with(2)->once();

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');
});

test('cooldown of 0 disables rate limiting', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 0.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->times(4)->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->twice();

    $this->resolver->hasPermission(1, 'a');
    $this->resolver->hasPermission(1, 'b');
});

test('flushUser resets warm cooldown for that user', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->times(4)->andReturn(false);
    // warmUser called twice because flushUser clears cooldown
    $this->cacheManager->shouldReceive('warmUser')->with(1)->twice();

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->flushUser(1);
    $this->resolver->hasPermission(1, 'x');
});

test('flush resets warm cooldown for all users', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->times(4)->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->twice();

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->flush();
    $this->resolver->hasPermission(1, 'x');
});

test('an answer given while the entry was missing is not kept, so the next check reads it', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    // Missing around the warm, then written by another process.
    $this->repository->shouldReceive('userCacheExists')->with(1)->times(3)->andReturn(false, false, true);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|posts.edit']);

    expect($this->resolver->hasPermission(1, 'posts.edit'))->toBeFalse()
        ->and($this->resolver->hasPermission(1, 'posts.edit'))->toBeTrue();
});

test('a role answer given while the entry was missing is not kept either', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->times(3)->andReturn(false, false, true);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);

    expect($this->resolver->hasRole(1, 'admin'))->toBeFalse()
        ->and($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('a warm that throws still starts the cooldown', function () {
    config()->set('permissions-redis.resolver_warm_cooldown', 5.0);

    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once()->andThrow(new RuntimeException('database down'));

    expect(fn () => $this->resolver->hasPermission(1, 'a'))->toThrow(RuntimeException::class);

    // Inside the cooldown: no second warm, the check answers false.
    expect($this->resolver->hasPermission(1, 'b'))->toBeFalse();
});

test('decodeEntry falls back to config auth.defaults.guard when auth manager is unavailable', function () {
    config()->set('auth.defaults.guard', 'api');

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['legacy.permission']);
    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['legacy.permission'])
        ->once()
        ->andReturn(['legacy.permission' => null]);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions->first()->guard)->toBe('api');
});

test('evictIfNeeded triggers when allPermissionsCache grows beyond limit even if permissionCache stays empty', function () {
    config()->set('permissions-redis.resolver_cache_limit', 3);

    for ($i = 1; $i <= 4; $i++) {
        $this->repository->shouldReceive('userCacheExists')->with($i)->andReturn(true);
        $this->repository->shouldReceive('getUserPermissions')->with($i)->andReturn(["web|perm.{$i}"]);
        $this->repository->shouldReceive('getPermissionGroups')
            ->with(["web|perm.{$i}"])
            ->andReturn(["web|perm.{$i}" => null]);
    }

    $this->resolver->getAllPermissions(1);
    $this->resolver->getAllPermissions(2);
    $this->resolver->getAllPermissions(3);
    $this->resolver->getAllPermissions(4);

    $reflection = new ReflectionProperty($this->resolver, 'allPermissionsCache');
    $reflection->setAccessible(true);
    $cache = $reflection->getValue($this->resolver);

    expect(count($cache))->toBeLessThanOrEqual(3)
        ->and(array_key_exists(4, $cache))->toBeTrue()
        ->and(array_key_exists(1, $cache))->toBeFalse();
});

test('getAllPermissions refetches groups on each call so cross-user group updates are visible', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create'])
        ->once()
        ->andReturn(['web|users.create' => 'First Group']);

    $first = $this->resolver->getAllPermissions(1);

    $this->repository->shouldReceive('getPermissionGroups')
        ->with(['web|users.create'])
        ->once()
        ->andReturn(['web|users.create' => 'Second Group']);

    $second = $this->resolver->getAllPermissions(1);

    expect($first->first()->group)->toBe('First Group')
        ->and($second->first()->group)->toBe('Second Group');
});

test('warm cooldown timestamps are evicted like the other per-user caches', function () {
    config()->set('permissions-redis.resolver_cache_limit', 3);
    config()->set('permissions-redis.resolver_warm_cooldown', 60.0);

    for ($i = 1; $i <= 5; $i++) {
        $this->repository->shouldReceive('userCacheExists')->with($i)->andReturn(false);
        $this->cacheManager->shouldReceive('warmUser')->with($i)->once();

        $this->resolver->hasPermission($i, 'x');
    }

    $attempts = (new ReflectionProperty($this->resolver, 'warmAttempts'))->getValue($this->resolver);

    expect(count($attempts))->toBeLessThanOrEqual(3)
        ->and(array_key_exists(5, $attempts))->toBeTrue()
        ->and(array_key_exists(1, $attempts))->toBeFalse();
});
