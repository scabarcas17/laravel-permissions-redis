<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, function () {
        return new AuthorizationCacheManager($this->repo);
    });
});

test('permissions-redis:warm warms all users and roles', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:warm')
        ->expectsOutputToContain('Warming authorization cache')
        ->expectsOutputToContain('warmed successfully')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions($user->id))->toContain('users.create')
        ->and($this->repo->getUserRoles($user->id))->toContain('admin');
});

test('permissions-redis:warm-user warms specific user', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'editor', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'posts.edit', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    $this->artisan("permissions-redis:warm-user {$user->id}")
        ->expectsOutputToContain("Warming authorization cache for user {$user->id}")
        ->assertSuccessful();

    expect($this->repo->getUserPermissions($user->id))->toContain('posts.edit');
});

test('permissions-redis:flush clears all cache when confirmed', function () {
    $this->repo->setUserPermissions(1, ['test.perm']);
    $this->repo->setUserRoles(1, ['admin']);

    $this->artisan('permissions-redis:flush')
        ->expectsConfirmation('This will remove all authorization cache entries. Continue?', 'yes')
        ->expectsOutputToContain('Authorization cache flushed')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions(1))->toBe([]);
});

test('permissions-redis:flush aborts when not confirmed', function () {
    $this->repo->setUserPermissions(1, ['test.perm']);

    $this->artisan('permissions-redis:flush')
        ->expectsConfirmation('This will remove all authorization cache entries. Continue?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions(1))->toContain('test.perm');
});
