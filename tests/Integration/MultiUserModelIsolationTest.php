<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\AdminUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));
});

test('scopeRole on User does not match AdminUser rows even though they share the users table', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $regularUser = User::create(['name' => 'Regular', 'email' => 'regular@test.com']);
    $adminUser = AdminUser::create(['name' => 'Admin', 'email' => 'admin@test.com']);

    DB::table('model_has_roles')->insert([
        ['role_id' => $role->id, 'model_id' => $regularUser->id, 'model_type' => User::class],
        ['role_id' => $role->id, 'model_id' => $adminUser->id, 'model_type' => AdminUser::class],
    ]);

    $userHits = User::query()->role('admin')->pluck('id')->all();
    $adminHits = AdminUser::query()->role('admin')->pluck('id')->all();

    expect($userHits)->toBe([$regularUser->id])
        ->and($adminHits)->toBe([$adminUser->id]);
});

test('User::roles() and AdminUser::roles() use model_type filter to stay isolated', function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $regularUser = User::create(['name' => 'Regular', 'email' => 'regular@test.com']);
    $adminUser = AdminUser::create(['name' => 'Admin', 'email' => 'admin@test.com']);

    DB::table('model_has_roles')->insert([
        ['role_id' => $role->id, 'model_id' => $regularUser->id, 'model_type' => User::class],
        ['role_id' => $role->id, 'model_id' => $adminUser->id, 'model_type' => AdminUser::class],
    ]);

    expect($regularUser->roles()->pluck('id')->all())->toBe([$role->id])
        ->and($adminUser->roles()->pluck('id')->all())->toBe([$role->id])
        ->and($regularUser->roles()->wherePivot('model_type', User::class)->count())->toBe(1)
        ->and($adminUser->roles()->wherePivot('model_type', AdminUser::class)->count())->toBe(1);
});
