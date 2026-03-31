<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Testing\WithPermissions;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\TestPermissionEnum;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

uses(WithPermissions::class);

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, function () {
        return new AuthorizationCacheManager($this->repo);
    });
});

test('seedPermissions creates permissions in database', function () {
    $permissions = $this->seedPermissions(['users.create', 'users.edit']);

    expect($permissions)->toHaveCount(2)
        ->and($permissions[0])->toBeInstanceOf(Permission::class)
        ->and($permissions[0]->name)->toBe('users.create')
        ->and($permissions[1]->name)->toBe('users.edit');

    $this->assertDatabaseHas('permissions', ['name' => 'users.create', 'guard_name' => 'web']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.edit', 'guard_name' => 'web']);
});

test('seedPermissions supports BackedEnum', function () {
    $permissions = $this->seedPermissions([TestPermissionEnum::Create]);

    expect($permissions)->toHaveCount(1)
        ->and($permissions[0]->name)->toBe('users.create');
});

test('seedPermissions accepts custom guard', function () {
    $permissions = $this->seedPermissions(['api.access'], 'api');

    expect($permissions[0]->guard_name)->toBe('api');
});

test('seedRoles creates roles with permissions', function () {
    $roles = $this->seedRoles([
        'admin'  => ['users.create', 'users.edit'],
        'editor' => ['posts.edit'],
    ]);

    expect($roles)->toHaveCount(2)
        ->and($roles['admin'])->toBeInstanceOf(Role::class)
        ->and($roles['editor'])->toBeInstanceOf(Role::class);

    $this->assertDatabaseHas('roles', ['name' => 'admin']);
    $this->assertDatabaseHas('roles', ['name' => 'editor']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
    $this->assertDatabaseHas('permissions', ['name' => 'posts.edit']);
});

test('actingAsWithPermissions assigns permissions and authenticates user', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $this->actingAsWithPermissions($user, ['users.create', 'users.edit']);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);

    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
    $this->assertDatabaseHas('permissions', ['name' => 'users.edit']);
});

test('actingAsWithRoles assigns roles and authenticates user', function () {
    $user = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);

    $this->actingAsWithRoles($user, ['admin', 'editor']);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);

    $this->assertDatabaseHas('roles', ['name' => 'admin']);
    $this->assertDatabaseHas('roles', ['name' => 'editor']);
});

test('flushPermissionCache clears repository and resolver', function () {
    $this->repo->setUserPermissions(1, ['users.create']);
    $this->repo->setUserRoles(1, ['admin']);

    $this->flushPermissionCache();

    expect($this->repo->getUserPermissions(1))->toBe([])
        ->and($this->repo->getUserRoles(1))->toBe([]);
});
