<?php

declare(strict_types=1);

use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

beforeEach(function () {
    $this->repository = Mockery::mock(PermissionRepositoryInterface::class);
    $this->manager = new AuthorizationCacheManager($this->repository);
});

test('evictUser delegates to repository deleteUserCache', function () {
    $this->repository->shouldReceive('deleteUserCache')->with(5)->once();

    $this->manager->evictUser(5);
});

test('evictRole delegates to repository deleteRoleCache', function () {
    $this->repository->shouldReceive('deleteRoleCache')->with(3)->once();

    $this->manager->evictRole(3);
});
