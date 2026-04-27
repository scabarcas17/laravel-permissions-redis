<?php

declare(strict_types=1);

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

test('seed command uses --guard option for roles and permissions', function () {
    config()->set('permissions-redis.seed', [
        'roles' => [
            'api-admin' => ['api.users.create'],
        ],
        'permissions' => ['api.reports.view'],
    ]);

    $this->artisan('permissions-redis:seed --guard=api --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('roles', ['name' => 'api-admin', 'guard_name' => 'api']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.users.create', 'guard_name' => 'api']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.reports.view', 'guard_name' => 'api']);
});

test('seed command reads guard from seed config', function () {
    config()->set('permissions-redis.seed', [
        'guard' => 'api',
        'roles' => [
            'api-editor' => ['api.posts.edit'],
        ],
    ]);

    $this->artisan('permissions-redis:seed --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('roles', ['name' => 'api-editor', 'guard_name' => 'api']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.posts.edit', 'guard_name' => 'api']);
});

test('seed command --guard option overrides config guard', function () {
    config()->set('permissions-redis.seed', [
        'guard' => 'api',
        'roles' => [
            'admin' => ['users.create'],
        ],
    ]);

    $this->artisan('permissions-redis:seed --guard=sanctum --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'sanctum']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.create', 'guard_name' => 'sanctum']);
});

test('seed command supports verbose role format with guard per role', function () {
    config()->set('permissions-redis.seed', [
        'roles' => [
            'admin'     => ['users.create'],
            'api_admin' => [
                'guard'       => 'api',
                'permissions' => ['api.users.read', 'api.users.write'],
            ],
        ],
    ]);

    $this->artisan('permissions-redis:seed --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'web']);
    $this->assertDatabaseHas('roles', ['name' => 'api_admin', 'guard_name' => 'api']);

    $this->assertDatabaseHas('permissions', ['name' => 'users.create', 'guard_name' => 'web']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.users.read', 'guard_name' => 'api']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.users.write', 'guard_name' => 'api']);
});

test('seed command supports verbose permission format with guard', function () {
    config()->set('permissions-redis.seed', [
        'permissions' => [
            'reports.export',
            ['name' => 'api.export', 'guard' => 'api'],
        ],
    ]);

    $this->artisan('permissions-redis:seed --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('permissions', ['name' => 'reports.export', 'guard_name' => 'web']);
    $this->assertDatabaseHas('permissions', ['name' => 'api.export', 'guard_name' => 'api']);
});

test('seed command verbose role creates its permissions on role guard even when default differs', function () {
    config()->set('permissions-redis.seed', [
        'guard' => 'web',
        'roles' => [
            'api_admin' => [
                'guard'       => 'api',
                'permissions' => ['api.scoped.action'],
            ],
        ],
    ]);

    $this->artisan('permissions-redis:seed --no-warm')
        ->assertSuccessful();

    $this->assertDatabaseHas('permissions', ['name' => 'api.scoped.action', 'guard_name' => 'api']);
    $this->assertDatabaseMissing('permissions', ['name' => 'api.scoped.action', 'guard_name' => 'web']);
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

test('seed command --fresh flushes Redis so removed permissions leave no stale group metadata', function () {
    $this->repo->setUserPermissions(1, ['web|legacy.permission']);
    $this->repo->setUserRoles(1, ['web|legacy-role']);
    $this->repo->setRolePermissions(42, ['web|legacy.permission']);
    $this->repo->setRoleUsers(42, [1]);
    $this->repo->replacePermissionGroups([
        'web|legacy.permission' => 'Legacy Group',
    ]);

    Permission::create(['name' => 'old.permission', 'guard_name' => 'web', 'group' => 'Old']);
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

    expect($this->repo->getUserPermissions(1))->toBe([])
        ->and($this->repo->getUserRoles(1))->toBe([])
        ->and($this->repo->getRoleUserIds(42))->toBe([])
        ->and($this->repo->getPermissionGroups(['web|legacy.permission']))
            ->toBe(['web|legacy.permission' => null]);

    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
    $this->assertDatabaseHas('roles', ['name' => 'admin']);
});
