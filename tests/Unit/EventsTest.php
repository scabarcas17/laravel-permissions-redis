<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;
use Scabarcas\LaravelPermissionsRedis\Events\RolesAssigned;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

test('PermissionsSynced holds role model', function () {
    $role = new Role();
    $role->id = 1;

    $event = new PermissionsSynced($role);

    expect($event->role)->toBe($role)
        ->and($event->role->getKey())->toBe(1);
});

test('RolesAssigned holds user model', function () {
    $user = new User();
    $user->id = 5;

    $event = new RolesAssigned($user);

    expect($event->user)->toBe($user)
        ->and($event->user->getKey())->toBe(5);
});

test('RoleDeleted holds role id', function () {
    $event = new RoleDeleted(10);

    expect($event->roleId)->toBe(10);
});

test('UserDeleted holds user id', function () {
    $event = new UserDeleted(20);

    expect($event->userId)->toBe(20);
});
