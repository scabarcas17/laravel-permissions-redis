<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;

// ─── PermissionResolver flush between requests ───

test('flush clears all in-memory caches', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    // Populate in-memory cache
    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with(1, 'web|posts.create')->once()->andReturn(true);
    $resolver->hasPermission(1, 'posts.create');

    // Simulate Octane request boundary
    $resolver->flush();

    // After flush, should hit repository again (not in-memory cache)
    $repository->shouldReceive('userHasPermission')->with(1, 'web|posts.create')->once()->andReturn(false);
    expect($resolver->hasPermission(1, 'posts.create'))->toBeFalse();
});

test('flush clears role cache', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(true);
    $resolver->hasRole(1, 'admin');

    $resolver->flush();

    $repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(false);
    expect($resolver->hasRole(1, 'admin'))->toBeFalse();
});

test('flush clears allPermissions cache', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|posts.create']);
    $resolver->getAllPermissions(1);

    $resolver->flush();

    $repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['web|posts.create', 'web|posts.edit']);
    $permissions = $resolver->getAllPermissions(1);
    expect($permissions)->toHaveCount(2);
});

test('flush clears allRoles cache', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin']);
    $resolver->getAllRoles(1);

    $resolver->flush();

    $repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['web|admin', 'web|editor']);
    $roles = $resolver->getAllRoles(1);
    expect($roles)->toHaveCount(2);
});

// ─── RedisPermissionRepository resetState ───

test('resetState clears cached connection, prefix and ttl', function () {
    $repo = new RedisPermissionRepository();

    // Call resetState should not throw
    $repo->resetState();

    // Verify the object is still functional (properties were nulled)
    $reflection = new ReflectionClass($repo);

    $connProp = $reflection->getProperty('cachedConnection');
    $connProp->setAccessible(true);
    expect($connProp->getValue($repo))->toBeNull();

    $prefixProp = $reflection->getProperty('cachedPrefix');
    $prefixProp->setAccessible(true);
    expect($prefixProp->getValue($repo))->toBeNull();

    $ttlProp = $reflection->getProperty('cachedTtl');
    $ttlProp->setAccessible(true);
    expect($ttlProp->getValue($repo))->toBeNull();
});

// ─── Simulated multi-request scenario ───

test('stale permission data does not leak after flush', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    // Request 1: user 1 has admin role
    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(true);
    expect($resolver->hasRole(1, 'admin'))->toBeTrue();

    // Request 2: user 1 lost admin role (simulating Octane boundary)
    $resolver->flush();

    $repository->shouldReceive('userHasRole')->with(1, 'web|admin')->once()->andReturn(false);
    expect($resolver->hasRole(1, 'admin'))->toBeFalse();
});

test('different users do not share cached data after flush', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    // Request 1: user 1 check
    $repository->shouldReceive('userCacheExists')->with(1)->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with(1, 'web|admin.panel')->once()->andReturn(true);
    $resolver->hasPermission(1, 'admin.panel');

    // Request 2: different user, flush between
    $resolver->flush();

    $repository->shouldReceive('userCacheExists')->with(2)->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with(2, 'web|admin.panel')->once()->andReturn(false);
    expect($resolver->hasPermission(2, 'admin.panel'))->toBeFalse();
});
