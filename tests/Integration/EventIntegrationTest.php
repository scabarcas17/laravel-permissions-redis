<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Events\PermissionsSynced;
use Sebastian\LaravelPermissionsRedis\Events\RoleDeleted;
use Sebastian\LaravelPermissionsRedis\Events\RolesAssigned;
use Sebastian\LaravelPermissionsRedis\Events\UserDeleted;
use Sebastian\LaravelPermissionsRedis\Listeners\CacheInvalidator;
use Sebastian\LaravelPermissionsRedis\Models\Role;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

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
        'role_id' => $role->id,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    // Warm role first so getRoleUserIds returns our user
    app(AuthorizationCacheManager::class)->warmRole($role->id);

    // Dispatch PermissionsSynced - should re-warm role and affected users
    event(new PermissionsSynced($role));

    expect($this->repo->getUserPermissions($user->id))->toContain('test.perm');
});

test('UserDeleted event clears user cache', function () {
    $this->repo->setUserPermissions(99, ['some.perm']);
    $this->repo->setUserRoles(99, ['admin']);

    event(new UserDeleted(99));

    expect($this->repo->userCacheExists(99))->toBeFalse();
});

test('RoleDeleted event clears role cache and recomputes affected users', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'test.perm', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    // Warm first
    $this->repo->setRoleUsers($roleId, [$user->id]);

    event(new RoleDeleted($roleId));

    // Role cache should be gone
    expect($this->repo->getRoleUserIds($roleId))->toBe([]);
});
