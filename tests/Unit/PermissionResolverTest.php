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
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('hasPermission returns false when user lacks permission', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.delete')->once()->andReturn(false);

    expect($this->resolver->hasPermission(1, 'users.delete'))->toBeFalse();
});

test('hasPermission uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(true);

    $this->resolver->hasPermission(1, 'users.create');
    $result = $this->resolver->hasPermission(1, 'users.create');

    expect($result)->toBeTrue();
});

test('hasPermission warms cache when Redis cache is empty', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('hasPermission respects explicit guard parameter', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'api|users.create')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeTrue();
});

test('hasPermission isolates permissions by guard', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'api|users.create')->once()->andReturn(false);

    expect($this->resolver->hasPermission(1, 'users.create', 'web'))->toBeTrue();
    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeFalse();
});

// ─── hasRole ───

test('hasRole returns true when user has role in Redis', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(true);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('hasRole returns false when user lacks role', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(false);

    expect($this->resolver->hasRole(1, 'admin'))->toBeFalse();
});

test('hasRole uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(true);

    $this->resolver->hasRole(1, 'admin');
    $result = $this->resolver->hasRole(1, 'admin');

    expect($result)->toBeTrue();
});

test('hasRole warms cache when Redis cache is empty', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(true);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('hasRole respects explicit guard parameter', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'api|admin')->once()->andReturn(false);

    expect($this->resolver->hasRole(1, 'admin', 'api'))->toBeFalse();
});

// ─── getAllPermissions ───

test('getAllPermissions returns collection of PermissionDTO', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create', 'web|users.edit']);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(2)
        ->and($permissions->first())->toBeInstanceOf(PermissionDTO::class)
        ->and($permissions->first()->name)->toBe('users.create')
        ->and($permissions->first()->guard)->toBe('web')
        ->and($permissions->last()->name)->toBe('users.edit');
});

test('getAllPermissions populates individual permission cache', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

    $this->resolver->getAllPermissions(1);

    // Second call to hasPermission should not hit Redis
    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('getAllPermissions uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.create']);

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

    $webPermissions = $this->resolver->getAllPermissions(1, 'web');
    expect($webPermissions)->toHaveCount(2);

    $apiPermissions = $this->resolver->getAllPermissions(1, 'api');
    expect($apiPermissions)->toHaveCount(1)
        ->and($apiPermissions->first()->name)->toBe('users.create')
        ->and($apiPermissions->first()->guard)->toBe('api');
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

test('getAllRoles populates individual role cache', function () {
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
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->twice()->andReturn(true);

    $this->resolver->hasPermission(1, 'users.create');
    $this->resolver->flush();
    $this->resolver->hasPermission(1, 'users.create');
});

// ─── super admin ───

test('super admin bypasses all permission checks', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|super-admin')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'anything'))->toBeTrue();
});

test('non super admin falls through to normal check', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|super-admin')->once()->andReturn(false);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(false);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeFalse();
});

test('super admin bypasses across all guards', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'token', 'provider' => 'users'],
    ]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    // Super admin role exists only in web guard
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|super-admin')->once()->andReturn(true);

    // But check passes even for api guard
    expect($this->resolver->hasPermission(1, 'anything', 'api'))->toBeTrue();
});

// ─── wildcard permissions ───

test('wildcard permission matches with fnmatch', function () {
    config()->set('permissions-redis.wildcard_permissions', true);

    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(false);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.*', 'web|posts.view']);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('wildcard does not match when disabled', function () {
    config()->set('permissions-redis.wildcard_permissions', false);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|users.create')->once()->andReturn(false);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeFalse();
});

test('super admin result is cached in memory', function () {
    config()->set('permissions-redis.super_admin_role', 'super-admin');
    config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'web|super-admin')->once()->andReturn(true);

    // First call checks Redis, second uses superAdminCache
    expect($this->resolver->hasPermission(1, 'anything'))->toBeTrue();
    expect($this->resolver->hasPermission(1, 'something_else'))->toBeTrue();
});

test('legacy bare value decoded with default guard fallback', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    // Repository returns legacy non-guard-encoded values
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['users.create', 'users.edit']);

    $permissions = $this->resolver->getAllPermissions(1);

    // Should decode using default guard
    expect($permissions)->toHaveCount(2)
        ->and($permissions->first()->name)->toBe('users.create')
        ->and($permissions->first()->guard)->toBe('web');
});

test('wildcard does not match across guards', function () {
    config()->set('permissions-redis.wildcard_permissions', true);

    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'api|users.create')->once()->andReturn(false);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|users.*']);

    // users.* is in web guard, checking api guard — should not match
    expect($this->resolver->hasPermission(1, 'users.create', 'api'))->toBeFalse();
});

// ─── flush ───

test('flushUser clears only specific user cache', function () {
    // Populate cache for user 1 and 2
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userCacheExists')->with(2)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'web|x')->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(2, 'web|x')->once()->andReturn(true);

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');

    $this->resolver->flushUser(1);

    // User 1 should hit Redis again, user 2 should use memory
    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');
});
