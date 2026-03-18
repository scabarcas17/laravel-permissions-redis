<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;

test('Permission model uses configurable table name', function () {
    $permission = new Permission();

    expect($permission->getTable())->toBe('permissions');
});

test('Permission model creates and retrieves correctly', function () {
    $permission = Permission::create([
        'name'        => 'users.create',
        'description' => 'Create users',
        'group'       => 'users',
        'guard_name'  => 'web',
    ]);

    expect($permission)->toBeInstanceOf(Permission::class)
        ->and($permission->name)->toBe('users.create')
        ->and($permission->description)->toBe('Create users')
        ->and($permission->group)->toBe('users')
        ->and($permission->guard_name)->toBe('web');

    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
});

test('Role model uses configurable table name', function () {
    $role = new Role();

    expect($role->getTable())->toBe('roles');
});

test('Role model creates and retrieves correctly', function () {
    $role = Role::create([
        'name'        => 'admin',
        'description' => 'Administrator',
        'guard_name'  => 'web',
    ]);

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->name)->toBe('admin')
        ->and($role->description)->toBe('Administrator')
        ->and($role->guard_name)->toBe('web');

    $this->assertDatabaseHas('roles', ['name' => 'admin']);
});

test('Role has many-to-many permissions relationship', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $perm1 = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);
    $perm2 = Permission::create(['name' => 'users.edit', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert([
        ['role_id' => $role->id, 'permission_id' => $perm1->id],
        ['role_id' => $role->id, 'permission_id' => $perm2->id],
    ]);

    $permissions = $role->permissions()->get();

    expect($permissions)->toHaveCount(2)
        ->and($permissions->pluck('name')->sort()->values()->all())->toBe(['users.create', 'users.edit']);
});
