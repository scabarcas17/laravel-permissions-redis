<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;

// ─── UUID user IDs with PermissionResolver ───

test('hasPermission works with UUID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repository->shouldReceive('userCacheExists')->with($uuid)->once()->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with($uuid, 'web|posts.create')->once()->andReturn(true);

    expect($resolver->hasPermission($uuid, 'posts.create'))->toBeTrue();
});

test('hasRole works with UUID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repository->shouldReceive('userCacheExists')->with($uuid)->once()->andReturn(true);
    $repository->shouldReceive('userHasRole')->with($uuid, 'web|admin')->once()->andReturn(true);

    expect($resolver->hasRole($uuid, 'admin'))->toBeTrue();
});

test('getAllPermissions works with UUID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repository->shouldReceive('userCacheExists')->with($uuid)->once()->andReturn(true);
    $repository->shouldReceive('getUserPermissions')->with($uuid)->once()->andReturn(['web|posts.create', 'web|posts.edit']);

    $permissions = $resolver->getAllPermissions($uuid);

    expect($permissions)->toHaveCount(2);
    expect($permissions->first()->name)->toBe('posts.create');
});

test('getAllRoles works with UUID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repository->shouldReceive('userCacheExists')->with($uuid)->once()->andReturn(true);
    $repository->shouldReceive('getUserRoles')->with($uuid)->once()->andReturn(['web|admin', 'web|editor']);

    $roles = $resolver->getAllRoles($uuid);

    expect($roles)->toHaveCount(2);
    expect($roles->first())->toBe('admin');
});

test('flushUser works with UUID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    // Populate in-memory cache (first call)
    $repository->shouldReceive('userCacheExists')->with($uuid)->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with($uuid, 'web|posts.create')->once()->andReturn(true);
    expect($resolver->hasPermission($uuid, 'posts.create'))->toBeTrue();

    // Flush user's in-memory cache
    $resolver->flushUser($uuid);

    // After flush, resolver should query repository again (not serve from in-memory)
    $repository->shouldReceive('userHasPermission')->with($uuid, 'web|posts.create')->once()->andReturn(false);
    expect($resolver->hasPermission($uuid, 'posts.create'))->toBeFalse();
});

// ─── UUID user IDs with InMemoryPermissionRepository ───

test('InMemoryPermissionRepository works with UUID user IDs', function () {
    $repo = new InMemoryPermissionRepository();
    $uuid = '01HXYZ1234567890ABCDEFGHIJ';

    $repo->setUserPermissions($uuid, ['web|posts.create', 'web|posts.edit']);
    $repo->setUserRoles($uuid, ['web|admin']);

    expect($repo->userHasPermission($uuid, 'web|posts.create'))->toBeTrue();
    expect($repo->userHasRole($uuid, 'web|admin'))->toBeTrue();
    expect($repo->getUserPermissions($uuid))->toBe(['web|posts.create', 'web|posts.edit']);
    expect($repo->getUserRoles($uuid))->toBe(['web|admin']);
    expect($repo->userCacheExists($uuid))->toBeTrue();
});

test('InMemoryPermissionRepository deleteUserCache works with UUID', function () {
    $repo = new InMemoryPermissionRepository();
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repo->setUserPermissions($uuid, ['web|posts.create']);
    $repo->deleteUserCache($uuid);

    expect($repo->userCacheExists($uuid))->toBeFalse();
    expect($repo->getUserPermissions($uuid))->toBe([]);
});

// ─── ULID user IDs ───

test('resolver works with ULID string user ID', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $repository->shouldReceive('userCacheExists')->with($ulid)->once()->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with($ulid, 'web|users.manage')->once()->andReturn(true);

    expect($resolver->hasPermission($ulid, 'users.manage'))->toBeTrue();
});

// ─── Mixed int and string IDs in same resolver ───

test('resolver handles both int and string user IDs simultaneously', function () {
    $repository = Mockery::mock(PermissionRepositoryInterface::class);
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $resolver = new PermissionResolver($repository, $cacheManager);

    $intId = 42;
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $repository->shouldReceive('userCacheExists')->with($intId)->andReturn(true);
    $repository->shouldReceive('userCacheExists')->with($uuid)->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with($intId, 'web|posts.create')->once()->andReturn(true);
    $repository->shouldReceive('userHasPermission')->with($uuid, 'web|posts.create')->once()->andReturn(false);

    expect($resolver->hasPermission($intId, 'posts.create'))->toBeTrue();
    expect($resolver->hasPermission($uuid, 'posts.create'))->toBeFalse();
});
