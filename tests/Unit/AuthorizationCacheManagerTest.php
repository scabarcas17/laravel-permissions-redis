<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

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

test('warmAll invokes flushAll before any other repository call', function () {
    $callOrder = [];

    $this->repository->shouldReceive('flushAll')->once()->andReturnUsing(function () use (&$callOrder) {
        $callOrder[] = 'flushAll';
    });
    $this->repository->shouldReceive('replaceSetBatch')->zeroOrMoreTimes()->andReturnUsing(function () use (&$callOrder) {
        $callOrder[] = 'replaceSetBatch';
    });
    $this->repository->shouldReceive('replacePermissionGroups')->zeroOrMoreTimes()->andReturnUsing(function () use (&$callOrder) {
        $callOrder[] = 'replacePermissionGroups';
    });

    $this->manager->warmAll();

    expect($callOrder[0] ?? null)->toBe('flushAll');
});

test('rewarmAll never invokes flushAll', function () {
    $this->repository->shouldNotReceive('flushAll');
    $this->repository->shouldReceive('replaceSetBatch')->zeroOrMoreTimes();
    $this->repository->shouldReceive('replacePermissionGroups')->zeroOrMoreTimes();

    $this->manager->rewarmAll();
});
