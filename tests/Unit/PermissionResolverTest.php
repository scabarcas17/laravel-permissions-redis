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
    $this->repository->shouldReceive('userHasPermission')->with(1, 'users.create')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('hasPermission returns false when user lacks permission', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'users.delete')->once()->andReturn(false);

    expect($this->resolver->hasPermission(1, 'users.delete'))->toBeFalse();
});

test('hasPermission uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'users.create')->once()->andReturn(true);

    $this->resolver->hasPermission(1, 'users.create');
    $result = $this->resolver->hasPermission(1, 'users.create');

    expect($result)->toBeTrue();
});

test('hasPermission warms cache when Redis cache is empty', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('userHasPermission')->with(1, 'users.create')->once()->andReturn(true);

    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

// ─── hasRole ───

test('hasRole returns true when user has role in Redis', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'admin')->once()->andReturn(true);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

test('hasRole returns false when user lacks role', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'admin')->once()->andReturn(false);

    expect($this->resolver->hasRole(1, 'admin'))->toBeFalse();
});

test('hasRole uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasRole')->with(1, 'admin')->once()->andReturn(true);

    $this->resolver->hasRole(1, 'admin');
    $result = $this->resolver->hasRole(1, 'admin');

    expect($result)->toBeTrue();
});

test('hasRole warms cache when Redis cache is empty', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(false);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->repository->shouldReceive('userHasRole')->with(1, 'admin')->once()->andReturn(true);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

// ─── getAllPermissions ───

test('getAllPermissions returns collection of PermissionDTO', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['users.create', 'users.edit']);

    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(2)
        ->and($permissions->first())->toBeInstanceOf(PermissionDTO::class)
        ->and($permissions->first()->name)->toBe('users.create')
        ->and($permissions->last()->name)->toBe('users.edit');
});

test('getAllPermissions populates individual permission cache', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['users.create']);

    $this->resolver->getAllPermissions(1);

    // Second call to hasPermission should not hit Redis
    expect($this->resolver->hasPermission(1, 'users.create'))->toBeTrue();
});

test('getAllPermissions uses in-memory cache on second call', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserPermissions')->with(1)->once()->andReturn(['users.create']);

    $this->resolver->getAllPermissions(1);
    $permissions = $this->resolver->getAllPermissions(1);

    expect($permissions)->toHaveCount(1);
});

// ─── getAllRoles ───

test('getAllRoles returns collection of role names', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['admin', 'editor']);

    $roles = $this->resolver->getAllRoles(1);

    expect($roles)->toHaveCount(2)
        ->and($roles->first())->toBe('admin')
        ->and($roles->last())->toBe('editor');
});

test('getAllRoles populates individual role cache', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->once()->andReturn(true);
    $this->repository->shouldReceive('getUserRoles')->with(1)->once()->andReturn(['admin']);

    $this->resolver->getAllRoles(1);

    expect($this->resolver->hasRole(1, 'admin'))->toBeTrue();
});

// ─── flush ───

test('flush clears all in-memory caches', function () {
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'users.create')->twice()->andReturn(true);

    $this->resolver->hasPermission(1, 'users.create');
    $this->resolver->flush();
    $this->resolver->hasPermission(1, 'users.create');
});

test('flushUser clears only specific user cache', function () {
    // Populate cache for user 1 and 2
    $this->repository->shouldReceive('userCacheExists')->with(1)->twice()->andReturn(true);
    $this->repository->shouldReceive('userCacheExists')->with(2)->once()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(1, 'x')->twice()->andReturn(true);
    $this->repository->shouldReceive('userHasPermission')->with(2, 'x')->once()->andReturn(true);

    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');

    $this->resolver->flushUser(1);

    // User 1 should hit Redis again, user 2 should use memory
    $this->resolver->hasPermission(1, 'x');
    $this->resolver->hasPermission(2, 'x');
});
