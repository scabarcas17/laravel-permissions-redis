<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));

    $this->user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $this->adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $this->editorRole = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->createPerm = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);
    $this->editPerm = Permission::create(['name' => 'users.edit', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert([
        ['role_id' => $this->adminRole->id, 'permission_id' => $this->createPerm->id],
        ['role_id' => $this->adminRole->id, 'permission_id' => $this->editPerm->id],
    ]);

    DB::table('model_has_roles')->insert([
        'role_id'    => $this->adminRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);

    app(AuthorizationCacheManager::class)->warmUser($this->user->id);
});

// ─── @role ───

test('role directive returns true when user has role', function () {
    $this->actingAs($this->user);

    expect(Blade::check('role', 'admin'))->toBeTrue();
});

test('role directive returns false when user lacks role', function () {
    $this->actingAs($this->user);

    expect(Blade::check('role', 'editor'))->toBeFalse();
});

test('role directive returns false when not authenticated', function () {
    expect(Blade::check('role', 'admin'))->toBeFalse();
});

// ─── @hasanyrole ───

test('hasanyrole returns true when user has at least one role', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasanyrole', 'admin|editor'))->toBeTrue();
});

test('hasanyrole returns false when user has no matching roles', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasanyrole', 'editor|viewer'))->toBeFalse();
});

test('hasanyrole returns false when not authenticated', function () {
    expect(Blade::check('hasanyrole', 'admin'))->toBeFalse();
});

// ─── @hasallroles ───

test('hasallroles returns true when user has all roles', function () {
    DB::table('model_has_roles')->insert([
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    $this->actingAs($this->user);

    expect(Blade::check('hasallroles', 'admin|editor'))->toBeTrue();
});

test('hasallroles returns false when user lacks one role', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasallroles', 'admin|editor'))->toBeFalse();
});

test('hasallroles returns false when not authenticated', function () {
    expect(Blade::check('hasallroles', 'admin'))->toBeFalse();
});

// ─── @permission ───

test('permission directive returns true when user has permission', function () {
    $this->actingAs($this->user);

    expect(Blade::check('permission', 'users.create'))->toBeTrue();
});

test('permission directive returns false when user lacks permission', function () {
    $this->actingAs($this->user);

    expect(Blade::check('permission', 'dashboard.view'))->toBeFalse();
});

test('permission directive returns false when not authenticated', function () {
    expect(Blade::check('permission', 'users.create'))->toBeFalse();
});

// ─── @hasanypermission ───

test('hasanypermission returns true when at least one matches', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasanypermission', 'dashboard.view|users.create'))->toBeTrue();
});

test('hasanypermission returns false when none match', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasanypermission', 'dashboard.view|reports.export'))->toBeFalse();
});

test('hasanypermission returns false when not authenticated', function () {
    expect(Blade::check('hasanypermission', 'users.create'))->toBeFalse();
});

// ─── @hasallpermissions ───

test('hasallpermissions returns true when user has all', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasallpermissions', 'users.create|users.edit'))->toBeTrue();
});

test('hasallpermissions returns false when user lacks one', function () {
    $this->actingAs($this->user);

    expect(Blade::check('hasallpermissions', 'users.create|dashboard.view'))->toBeFalse();
});

test('hasallpermissions returns false when not authenticated', function () {
    expect(Blade::check('hasallpermissions', 'users.create'))->toBeFalse();
});

// ─── Guard override ───

test('role directive accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserRoles($this->user->id, ['web|admin', 'api|editor']);

    expect(Blade::check('role', 'admin', 'web'))->toBeTrue()
        ->and(Blade::check('role', 'editor', 'api'))->toBeTrue()
        // admin only exists in web guard
        ->and(Blade::check('role', 'admin', 'api'))->toBeFalse();
});

test('permission directive accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserPermissions($this->user->id, ['web|users.create', 'api|tokens.issue']);

    expect(Blade::check('permission', 'users.create', 'web'))->toBeTrue()
        ->and(Blade::check('permission', 'tokens.issue', 'api'))->toBeTrue()
        ->and(Blade::check('permission', 'users.create', 'api'))->toBeFalse();
});

test('hasanyrole accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserRoles($this->user->id, ['api|editor']);

    expect(Blade::check('hasanyrole', 'admin|editor', 'api'))->toBeTrue()
        ->and(Blade::check('hasanyrole', 'admin|editor', 'web'))->toBeFalse();
});

test('hasanypermission accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserPermissions($this->user->id, ['api|tokens.issue']);

    expect(Blade::check('hasanypermission', 'users.create|tokens.issue', 'api'))->toBeTrue()
        ->and(Blade::check('hasanypermission', 'users.create|tokens.issue', 'web'))->toBeFalse();
});

test('hasallroles accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserRoles($this->user->id, ['api|admin', 'api|editor']);

    expect(Blade::check('hasallroles', 'admin|editor', 'api'))->toBeTrue()
        ->and(Blade::check('hasallroles', 'admin|editor', 'web'))->toBeFalse();
});

test('hasallpermissions accepts explicit guard parameter', function () {
    $this->actingAs($this->user);
    $this->repo->setUserPermissions($this->user->id, ['api|tokens.issue', 'api|tokens.revoke']);

    expect(Blade::check('hasallpermissions', 'tokens.issue|tokens.revoke', 'api'))->toBeTrue()
        ->and(Blade::check('hasallpermissions', 'tokens.issue|tokens.revoke', 'web'))->toBeFalse();
});
