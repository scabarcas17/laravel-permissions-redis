<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

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

test('Permission findOrCreate creates new permission', function () {
    $perm = Permission::findOrCreate('users.create', 'web', 'users');

    expect($perm->name)->toBe('users.create')
        ->and($perm->guard_name)->toBe('web')
        ->and($perm->group)->toBe('users');

    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
});

test('Permission findOrCreate returns existing permission', function () {
    $first = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);
    $second = Permission::findOrCreate('users.create', 'web');

    expect($second->id)->toBe($first->id);
    expect(Permission::where('name', 'users.create')->count())->toBe(1);
});

test('Permission findOrCreate rejects name containing pipe', function () {
    Permission::findOrCreate('invalid|name');
})->throws(InvalidArgumentException::class, "Permission name cannot contain the '|' character.");

test('Role findOrCreate creates new role', function () {
    $role = Role::findOrCreate('admin', 'web');

    expect($role->name)->toBe('admin')
        ->and($role->guard_name)->toBe('web');

    $this->assertDatabaseHas('roles', ['name' => 'admin']);
});

test('Role findOrCreate returns existing role', function () {
    $first = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $second = Role::findOrCreate('admin', 'web');

    expect($second->id)->toBe($first->id);
    expect(Role::where('name', 'admin')->count())->toBe(1);
});

test('Role findOrCreate rejects name containing pipe', function () {
    Role::findOrCreate('invalid|name');
})->throws(InvalidArgumentException::class, "Role name cannot contain the '|' character.");

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

test('Role has many-to-many users relationship', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    DB::table('model_has_roles')->insert([
        'role_id'    => $role->id,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    $users = $role->users()->get();

    expect($users)->toHaveCount(1)
        ->and($users->first()->id)->toBe($user->id);
});

test('Permission updated event triggers cache warm for affected users', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    $perm = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $perm->id]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    $perm->update(['description' => 'Updated']);

    // warmAll should have been called, populating the cache
    expect($repo->getUserPermissions($user->id))->toContain('web|users.create');
});

test('Permission deleted event triggers cache warm for affected users', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    $perm = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    DB::table('model_has_permissions')->insert([
        'permission_id' => $perm->id, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    // Populate cache with this permission
    $repo->setUserPermissions($user->id, ['web|users.create']);

    $perm->delete();

    // Affected user's cache is re-warmed from DB (which no longer has the perm)
    expect($repo->getUserPermissions($user->id))->toBe([]);
});

test('Role deleted event dispatches RoleDeleted', function () {
    Event::fake([RoleDeleted::class]);

    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $roleId = $role->id;

    $role->delete();

    Event::assertDispatched(RoleDeleted::class, function (RoleDeleted $event) use ($roleId) {
        return $event->roleId === $roleId;
    });
});

test('Permission saved hook syncs group metadata to Redis hash', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    Permission::create([
        'name'       => 'users.create',
        'guard_name' => 'web',
        'group'      => 'User Management',
    ]);

    expect($repo->getPermissionGroups(['web|users.create']))
        ->toBe(['web|users.create' => 'User Management']);
});

test('Permission saved with null group stores null in Redis hash', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    Permission::create([
        'name'       => 'users.create',
        'guard_name' => 'web',
    ]);

    expect($repo->getPermissionGroups(['web|users.create']))
        ->toBe(['web|users.create' => null]);
});

test('Permission deleted hook removes group metadata from Redis hash', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    $perm = Permission::create([
        'name'       => 'users.create',
        'guard_name' => 'web',
        'group'      => 'User Management',
    ]);

    expect($repo->getPermissionGroups(['web|users.create']))
        ->toBe(['web|users.create' => 'User Management']);

    $perm->delete();

    expect($repo->getPermissionGroups(['web|users.create']))
        ->toBe(['web|users.create' => null]);
});
