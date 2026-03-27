<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;
use Scabarcas\LaravelPermissionsRedis\Events\RolesAssigned;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;
use Scabarcas\LaravelPermissionsRedis\Listeners\CacheInvalidator;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));
});

test('CacheInvalidator is registered as event subscriber', function () {
    // The service provider subscribes CacheInvalidator
    // Verify by dispatching events and checking the listener is called
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $permId = DB::table('permissions')->insertGetId(['name' => 'test.perm', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $role->id,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    // Warm role first so getRoleUserIds returns our user
    app(AuthorizationCacheManager::class)->warmRole($role->id);

    // Dispatch PermissionsSynced - should re-warm role and affected users
    event(new PermissionsSynced($role));

    expect($this->repo->getUserPermissions($user->id))->toContain('web|test.perm');
});

test('UserDeleted event clears user cache', function () {
    $this->repo->setUserPermissions(99, ['some.perm']);
    $this->repo->setUserRoles(99, ['admin']);

    event(new UserDeleted(99));

    expect($this->repo->userCacheExists(99))->toBeFalse();
});

test('RolesAssigned event warms user and recomputes role indexes', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $permId = DB::table('permissions')->insertGetId(['name' => 'test.perm', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $role->id,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    event(new RolesAssigned($user));

    // warmUser populates permissions and roles
    expect($this->repo->getUserPermissions($user->id))->toContain('web|test.perm')
        ->and($this->repo->getUserRoles($user->id))->toContain('web|admin')
        // rewarmUserRoleIndexes warms role cache with user reverse index
        ->and($this->repo->getRoleUserIds($role->id))->toContain($user->id);
});

test('RoleDeleted event clears role cache and recomputes affected users', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'test.perm', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    // Warm first
    $this->repo->setRoleUsers($roleId, [$user->id]);

    event(new RoleDeleted($roleId));

    // Role cache should be gone
    expect($this->repo->getRoleUserIds($roleId))->toBe([]);
});
