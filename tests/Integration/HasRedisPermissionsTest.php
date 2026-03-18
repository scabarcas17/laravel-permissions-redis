<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Events\RolesAssigned;
use Sebastian\LaravelPermissionsRedis\Models\Permission;
use Sebastian\LaravelPermissionsRedis\Models\Role;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);

    // Ensure singleton for CacheManager uses our repo
    $this->app->singleton(AuthorizationCacheManager::class, function ($app) {
        return new AuthorizationCacheManager($this->repo);
    });

    // Create test data
    $this->user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $this->adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $this->editorRole = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->createPerm = Permission::create(['name' => 'users.create', 'guard_name' => 'web']);
    $this->editPerm = Permission::create(['name' => 'users.edit', 'guard_name' => 'web']);
    $this->deletePerm = Permission::create(['name' => 'users.delete', 'guard_name' => 'web']);

    // Set up role -> permissions in DB
    DB::table('role_has_permissions')->insert([
        ['role_id' => $this->adminRole->id, 'permission_id' => $this->createPerm->id],
        ['role_id' => $this->adminRole->id, 'permission_id' => $this->editPerm->id],
        ['role_id' => $this->adminRole->id, 'permission_id' => $this->deletePerm->id],
        ['role_id' => $this->editorRole->id, 'permission_id' => $this->editPerm->id],
    ]);

    // Set up user -> role in DB
    DB::table('model_has_roles')->insert([
        'role_id' => $this->adminRole->id,
        'model_id' => $this->user->id,
        'model_type' => User::class,
    ]);

    // Warm the cache so the resolver can read from it
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);
});

// ─── Read methods ───

test('hasPermissionTo returns true for assigned permission', function () {
    expect($this->user->hasPermissionTo('users.create'))->toBeTrue();
});

test('hasPermissionTo returns false for unassigned permission', function () {
    expect($this->user->hasPermissionTo('dashboard.view'))->toBeFalse();
});

test('hasAnyPermission returns true when at least one matches', function () {
    expect($this->user->hasAnyPermission('dashboard.view', 'users.create'))->toBeTrue();
});

test('hasAnyPermission returns false when none match', function () {
    expect($this->user->hasAnyPermission('dashboard.view', 'reports.export'))->toBeFalse();
});

test('hasAllPermissions returns true when all match', function () {
    expect($this->user->hasAllPermissions('users.create', 'users.edit'))->toBeTrue();
});

test('hasAllPermissions returns false when one is missing', function () {
    expect($this->user->hasAllPermissions('users.create', 'dashboard.view'))->toBeFalse();
});

test('hasRole returns true for assigned role by string', function () {
    expect($this->user->hasRole('admin'))->toBeTrue();
});

test('hasRole returns false for unassigned role', function () {
    expect($this->user->hasRole('editor'))->toBeFalse();
});

test('hasRole accepts array and returns true if any match', function () {
    expect($this->user->hasRole(['editor', 'admin']))->toBeTrue();
});

test('hasAnyRole returns true when at least one matches', function () {
    expect($this->user->hasAnyRole('editor', 'admin'))->toBeTrue();
});

test('hasAnyRole returns false when none match', function () {
    expect($this->user->hasAnyRole('editor', 'viewer'))->toBeFalse();
});

test('hasAllRoles returns true when all match', function () {
    // Add editor role too
    DB::table('model_has_roles')->insert([
        'role_id' => $this->editorRole->id,
        'model_id' => $this->user->id,
        'model_type' => User::class,
    ]);
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    expect($this->user->hasAllRoles('admin', 'editor'))->toBeTrue();
});

test('hasAllRoles returns false when one is missing', function () {
    expect($this->user->hasAllRoles('admin', 'editor'))->toBeFalse();
});

test('getAllPermissions returns PermissionDTO collection', function () {
    $permissions = $this->user->getAllPermissions();

    expect($permissions)->toHaveCount(3)
        ->and($permissions->pluck('name')->sort()->values()->all())
        ->toBe(['users.create', 'users.delete', 'users.edit']);
});

test('getPermissionNames returns collection of strings', function () {
    $names = $this->user->getPermissionNames();

    expect($names->sort()->values()->all())->toBe(['users.create', 'users.delete', 'users.edit']);
});

test('getRoleNames returns collection of role names', function () {
    $names = $this->user->getRoleNames();

    expect($names->all())->toBe(['admin']);
});

// ─── Write methods ───

test('assignRole persists role in database and dispatches event', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->assignRole('editor');

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $this->editorRole->id,
        'model_id' => $this->user->id,
        'model_type' => User::class,
    ]);

    Event::assertDispatched(RolesAssigned::class);
});

test('syncRoles replaces all roles', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->syncRoles(['editor']);

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $this->adminRole->id,
        'model_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $this->editorRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('removeRole detaches role from user', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->removeRole('admin');

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $this->adminRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('givePermissionTo creates direct permission', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id' => $this->user->id,
        'model_type' => User::class,
    ]);
});

test('revokePermissionTo removes direct permission', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');
    $this->user->revokePermissionTo('special.access');

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id' => $this->user->id,
    ]);
});

test('syncPermissions replaces all direct permissions', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);
    $extraPerm = Permission::create(['name' => 'extra.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');
    $this->user->syncPermissions(['extra.access']);

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $extraPerm->id,
        'model_id' => $this->user->id,
    ]);
});

// ─── Relationships ───

test('roles relationship returns BelongsToMany', function () {
    $roles = $this->user->roles()->get();

    expect($roles)->toHaveCount(1)
        ->and($roles->first()->name)->toBe('admin');
});

test('permissions relationship returns BelongsToMany', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);
    $this->user->givePermissionTo('special.access');

    $permissions = $this->user->permissions()->get();

    expect($permissions)->toHaveCount(1)
        ->and($permissions->first()->name)->toBe('special.access');
});

// ─── Scopes ───

test('scopeRole filters users by role name', function () {
    $user2 = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);

    $result = User::query()->role('admin')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($this->user->id);
});

test('scopePermission filters users by permission name', function () {
    $result = User::query()->permission('users.create')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($this->user->id);
});

test('assignRole returns self for chaining', function () {
    Event::fake([RolesAssigned::class]);

    $result = $this->user->assignRole('editor');

    expect($result)->toBe($this->user);
});
