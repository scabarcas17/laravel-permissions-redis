<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, function () {
        return new AuthorizationCacheManager($this->repo);
    });
});

test('seed command warns when no seed config exists', function () {
    config()->set('permissions-redis.seed', []);

    $this->artisan('permissions-redis:seed')
        ->expectsOutputToContain('No seed data found')
        ->assertSuccessful();
});

test('seed command creates permissions and roles from config', function () {
    config()->set('permissions-redis.seed', [
        'roles' => [
            'admin'  => ['users.create', 'users.edit'],
            'editor' => ['posts.edit'],
        ],
        'permissions' => ['reports.view'],
    ]);

    $this->artisan('permissions-redis:seed')
        ->expectsOutputToContain('Seeded 4 permissions and 2 roles')
        ->assertSuccessful();

    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.edit']);
    $this->assertDatabaseHas('permissions', ['name' => 'posts.edit']);
    $this->assertDatabaseHas('permissions', ['name' => 'reports.view']);
    $this->assertDatabaseHas('roles', ['name' => 'admin']);
    $this->assertDatabaseHas('roles', ['name' => 'editor']);

    $admin = Role::where('name', 'admin')->first();
    expect($admin->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.edit']);
});

test('seed command does not duplicate existing data', function () {
    Permission::findOrCreate('users.create');
    Role::findOrCreate('admin');

    config()->set('permissions-redis.seed', [
        'roles' => [
            'admin' => ['users.create'],
        ],
    ]);

    $this->artisan('permissions-redis:seed')
        ->expectsOutputToContain('Seeded 0 permissions and 0 roles')
        ->assertSuccessful();

    expect(Permission::where('name', 'users.create')->count())->toBe(1)
        ->and(Role::where('name', 'admin')->count())->toBe(1);
});

test('seed command with --fresh deletes existing data in non-production', function () {
    Permission::findOrCreate('old.permission');
    Role::findOrCreate('old-role');

    config()->set('permissions-redis.seed', [
        'roles' => [
            'admin' => ['users.create'],
        ],
    ]);

    $this->artisan('permissions-redis:seed --fresh')
        ->expectsOutputToContain('Deleting all existing')
        ->expectsOutputToContain('Seeded 1 permissions and 1 roles')
        ->assertSuccessful();

    $this->assertDatabaseMissing('permissions', ['name' => 'old.permission']);
    $this->assertDatabaseMissing('roles', ['name' => 'old-role']);
});

test('seed command with --fresh asks confirmation in production', function () {
    $this->app->detectEnvironment(fn () => 'production');

    config()->set('permissions-redis.seed', [
        'permissions' => ['test.perm'],
    ]);

    $this->artisan('permissions-redis:seed --fresh')
        ->expectsConfirmation(
            'You are in PRODUCTION. This will delete ALL permissions and roles. Continue?',
            'no',
        )
        ->assertSuccessful();
});

test('seed command with --no-warm skips cache warming', function () {
    config()->set('permissions-redis.seed', [
        'permissions' => ['users.create'],
    ]);

    $this->artisan('permissions-redis:seed --no-warm')
        ->doesntExpectOutputToContain('Warming Redis cache')
        ->assertSuccessful();
});

test('seed command warms cache by default', function () {
    config()->set('permissions-redis.seed', [
        'permissions' => ['users.create'],
    ]);

    $this->artisan('permissions-redis:seed')
        ->expectsOutputToContain('Warming Redis cache')
        ->expectsOutputToContain('Cache warmed in')
        ->assertSuccessful();
});
