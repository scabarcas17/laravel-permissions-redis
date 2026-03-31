<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\TestPermissionEnum;

test('Role syncPermissions replaces all permissions and dispatches event', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $perm1 = Permission::findOrCreate('users.create');
    $perm2 = Permission::findOrCreate('users.edit');
    $perm3 = Permission::findOrCreate('posts.edit');

    $role->syncPermissions(['users.create', 'users.edit']);

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.edit']);

    // Sync again with different permissions replaces them
    $role->syncPermissions(['posts.edit']);

    expect($role->permissions()->pluck('name')->all())->toBe(['posts.edit']);

    Event::assertDispatched(PermissionsSynced::class);
});

test('Role givePermissionTo adds permissions without removing existing', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    Permission::findOrCreate('posts.edit');
    Permission::findOrCreate('posts.create');

    $role->givePermissionTo('posts.edit');
    $role->givePermissionTo('posts.create');

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['posts.create', 'posts.edit']);

    Event::assertDispatched(PermissionsSynced::class);
});

test('Role revokePermissionTo removes specific permissions', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $perm1 = Permission::findOrCreate('users.create');
    $perm2 = Permission::findOrCreate('users.edit');

    $role->syncPermissions(['users.create', 'users.edit']);
    $role->revokePermissionTo('users.edit');

    expect($role->permissions()->pluck('name')->all())->toBe(['users.create']);

    Event::assertDispatched(PermissionsSynced::class);
});

test('Role syncPermissions resolves integer IDs', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $perm = Permission::findOrCreate('users.create');

    $role->syncPermissions([$perm->id]);

    expect($role->permissions()->pluck('name')->all())->toBe(['users.create']);
});

test('Role syncPermissions resolves BackedEnum values', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Permission::findOrCreate('users.create');
    Permission::findOrCreate('users.edit');

    $role->syncPermissions([TestPermissionEnum::Create, TestPermissionEnum::Edit]);

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.edit']);
});

test('Role givePermissionTo returns self for fluent chaining', function () {
    Event::fake([PermissionsSynced::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Permission::findOrCreate('users.create');

    $result = $role->givePermissionTo('users.create');

    expect($result)->toBe($role);
});
