<?php

declare(strict_types=1);

use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Events\PermissionsSynced;
use Sebastian\LaravelPermissionsRedis\Events\RoleDeleted;
use Sebastian\LaravelPermissionsRedis\Events\RolesAssigned;
use Sebastian\LaravelPermissionsRedis\Events\UserDeleted;
use Sebastian\LaravelPermissionsRedis\Listeners\CacheInvalidator;
use Sebastian\LaravelPermissionsRedis\Models\Role;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $this->repository = Mockery::mock(PermissionRepositoryInterface::class);
    $this->invalidator = new CacheInvalidator($this->cacheManager, $this->repository);
});

test('handlePermissionsSynced warms role and all affected users', function () {
    $role = new Role();
    $role->id = 5;

    $this->cacheManager->shouldReceive('warmRole')->with(5)->once();
    $this->repository->shouldReceive('getRoleUserIds')->with(5)->once()->andReturn([1, 2, 3]);
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->cacheManager->shouldReceive('warmUser')->with(2)->once();
    $this->cacheManager->shouldReceive('warmUser')->with(3)->once();

    $this->invalidator->handlePermissionsSynced(new PermissionsSynced($role));
});

test('handlePermissionsSynced handles role with no users', function () {
    $role = new Role();
    $role->id = 5;

    $this->cacheManager->shouldReceive('warmRole')->with(5)->once();
    $this->repository->shouldReceive('getRoleUserIds')->with(5)->once()->andReturn([]);

    $this->invalidator->handlePermissionsSynced(new PermissionsSynced($role));
});

test('handleRolesAssigned warms user cache', function () {
    $user = new User();
    $user->id = 10;

    $this->cacheManager->shouldReceive('warmUser')->with(10)->once();
    // rewarmUserRoleIndexes queries DB - we mock by expecting warmRole calls
    // Since we use SQLite in testing, model_has_roles table exists but is empty
    $this->cacheManager->shouldReceive('warmRole')->zeroOrMoreTimes();

    $this->invalidator->handleRolesAssigned(new RolesAssigned($user));
});

test('handleRoleDeleted removes role cache and recomputes affected users', function () {
    $this->repository->shouldReceive('getRoleUserIds')->with(7)->once()->andReturn([1, 2]);
    $this->repository->shouldReceive('deleteRoleCache')->with(7)->once();
    $this->cacheManager->shouldReceive('warmUser')->with(1)->once();
    $this->cacheManager->shouldReceive('warmUser')->with(2)->once();

    $this->invalidator->handleRoleDeleted(new RoleDeleted(7));
});

test('handleRoleDeleted with no affected users', function () {
    $this->repository->shouldReceive('getRoleUserIds')->with(7)->once()->andReturn([]);
    $this->repository->shouldReceive('deleteRoleCache')->with(7)->once();

    $this->invalidator->handleRoleDeleted(new RoleDeleted(7));
});

test('handleUserDeleted removes user cache', function () {
    $this->repository->shouldReceive('deleteUserCache')->with(15)->once();

    $this->invalidator->handleUserDeleted(new UserDeleted(15));
});
