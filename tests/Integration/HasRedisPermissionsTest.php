<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsAssigned;
use Scabarcas\LaravelPermissionsRedis\Events\RolesAssigned;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\TestPermissionEnum;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\TestRoleEnum;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

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
        'role_id'    => $this->adminRole->id,
        'model_id'   => $this->user->id,
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
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
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
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);

    Event::assertDispatched(RolesAssigned::class);
});

test('syncRoles replaces all roles', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->syncRoles(['editor']);

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id'  => $this->adminRole->id,
        'model_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'  => $this->editorRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('removeRole detaches role from user', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->removeRole('admin');

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id'  => $this->adminRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('givePermissionTo creates direct permission', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id'      => $this->user->id,
        'model_type'    => User::class,
    ]);
});

test('revokePermissionTo removes direct permission', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');
    $this->user->revokePermissionTo('special.access');

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id'      => $this->user->id,
    ]);
});

test('syncPermissions replaces all direct permissions', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);
    $extraPerm = Permission::create(['name' => 'extra.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo('special.access');
    $this->user->syncPermissions(['extra.access']);

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id'      => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $extraPerm->id,
        'model_id'      => $this->user->id,
    ]);
});

test('givePermissionTo dispatches PermissionsAssigned event', function () {
    Permission::create(['name' => 'special.access', 'guard_name' => 'web']);
    Event::fake([PermissionsAssigned::class]);

    $this->user->givePermissionTo('special.access');

    Event::assertDispatched(PermissionsAssigned::class, fn (PermissionsAssigned $e): bool => $e->user->is($this->user));
});

test('revokePermissionTo dispatches PermissionsAssigned event', function () {
    Permission::create(['name' => 'special.access', 'guard_name' => 'web']);
    $this->user->givePermissionTo('special.access');

    Event::fake([PermissionsAssigned::class]);

    $this->user->revokePermissionTo('special.access');

    Event::assertDispatched(PermissionsAssigned::class);
});

test('syncPermissions dispatches PermissionsAssigned event', function () {
    Permission::create(['name' => 'extra.access', 'guard_name' => 'web']);
    Event::fake([PermissionsAssigned::class]);

    $this->user->syncPermissions(['extra.access']);

    Event::assertDispatched(PermissionsAssigned::class);
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

// ─── BackedEnum support ───

test('hasPermissionTo accepts BackedEnum', function () {
    expect($this->user->hasPermissionTo(TestPermissionEnum::Create))->toBeTrue()
        ->and($this->user->hasPermissionTo(TestPermissionEnum::Edit))->toBeTrue();
});

test('hasAnyPermission accepts BackedEnum', function () {
    expect($this->user->hasAnyPermission(TestPermissionEnum::Create, TestPermissionEnum::Edit))->toBeTrue();
});

test('hasRole accepts BackedEnum', function () {
    expect($this->user->hasRole(TestRoleEnum::Admin))->toBeTrue()
        ->and($this->user->hasRole(TestRoleEnum::Editor))->toBeFalse();
});

test('hasRole accepts integer role ID', function () {
    expect($this->user->hasRole($this->adminRole->id))->toBeTrue()
        ->and($this->user->hasRole($this->editorRole->id))->toBeFalse();
});

test('hasRole with array returns false when none match', function () {
    expect($this->user->hasRole(['editor', 'viewer']))->toBeFalse();
});

test('hasRole with Collection returns true when one matches', function () {
    expect($this->user->hasRole(collect(['editor', 'admin'])))->toBeTrue();
});

test('assignRole accepts integer role ID', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->assignRole($this->editorRole->id);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
});

test('assignRole accepts BackedEnum', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->assignRole(TestRoleEnum::Editor);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
});

test('givePermissionTo accepts integer permission ID', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo($specialPerm->id);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id'      => $this->user->id,
        'model_type'    => User::class,
    ]);
});

test('givePermissionTo accepts BackedEnum', function () {
    $this->user->givePermissionTo(TestPermissionEnum::Create);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $this->createPerm->id,
        'model_id'      => $this->user->id,
        'model_type'    => User::class,
    ]);
});

// ─── Scopes with integer IDs ───

test('assignRole handles float via catch-all cast', function () {
    Event::fake([RolesAssigned::class]);

    $this->user->assignRole(floatval($this->editorRole->id));

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
});

test('givePermissionTo handles float via catch-all cast', function () {
    $specialPerm = Permission::create(['name' => 'special.access', 'guard_name' => 'web']);

    $this->user->givePermissionTo(floatval($specialPerm->id));

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $specialPerm->id,
        'model_id'      => $this->user->id,
        'model_type'    => User::class,
    ]);
});

test('scopeRole accepts integer role ID', function () {
    $result = User::query()->role($this->adminRole->id)->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($this->user->id);
});

test('scopePermission accepts integer permission ID', function () {
    $result = User::query()->permission($this->createPerm->id)->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($this->user->id);
});

// ─── forGuard() fluent API ───

test('forGuard sets guard for hasPermissionTo', function () {
    // User has web|users.create but NOT api|users.create
    expect($this->user->forGuard('web')->hasPermissionTo('users.create'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasPermissionTo('users.create'))->toBeFalse();
});

test('forGuard auto-resets after use', function () {
    // After forGuard('api') is consumed, next call should use default guard (web)
    $this->user->forGuard('api');
    $this->user->hasPermissionTo('users.create'); // consumes 'api'

    // Next call should use default guard (web), not 'api'
    expect($this->user->hasPermissionTo('users.create'))->toBeTrue();
});

test('explicit guardName parameter takes precedence over forGuard', function () {
    // forGuard('api') but explicit 'web' should win
    expect($this->user->forGuard('api')->hasPermissionTo('users.create', 'web'))->toBeTrue();
});

test('forGuard works with hasAnyPermission', function () {
    expect($this->user->forGuard('web')->hasAnyPermission('users.create', 'users.edit'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasAnyPermission('users.create', 'users.edit'))->toBeFalse();
});

test('forGuard works with hasAllPermissions', function () {
    expect($this->user->forGuard('web')->hasAllPermissions('users.create', 'users.edit'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasAllPermissions('users.create', 'users.edit'))->toBeFalse();
});

test('forGuard works with hasRole', function () {
    expect($this->user->forGuard('web')->hasRole('admin'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasRole('admin'))->toBeFalse();
});

test('forGuard works with hasAnyRole', function () {
    expect($this->user->forGuard('web')->hasAnyRole('admin', 'editor'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasAnyRole('admin', 'editor'))->toBeFalse();
});

test('forGuard works with hasAllRoles', function () {
    DB::table('model_has_roles')->insert([
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    expect($this->user->forGuard('web')->hasAllRoles('admin', 'editor'))->toBeTrue()
        ->and($this->user->forGuard('api')->hasAllRoles('admin', 'editor'))->toBeFalse();
});

test('forGuard works with getAllPermissions', function () {
    $webPerms = $this->user->forGuard('web')->getAllPermissions();
    $apiPerms = $this->user->forGuard('api')->getAllPermissions();

    expect($webPerms)->toHaveCount(3)
        ->and($apiPerms)->toHaveCount(0);
});

test('forGuard works with getPermissionNames', function () {
    $webNames = $this->user->forGuard('web')->getPermissionNames();
    $apiNames = $this->user->forGuard('api')->getPermissionNames();

    expect($webNames->sort()->values()->all())->toBe(['users.create', 'users.delete', 'users.edit'])
        ->and($apiNames->all())->toBe([]);
});

test('forGuard works with getRoleNames', function () {
    $webRoles = $this->user->forGuard('web')->getRoleNames();
    $apiRoles = $this->user->forGuard('api')->getRoleNames();

    expect($webRoles->all())->toBe(['admin'])
        ->and($apiRoles->all())->toBe([]);
});

test('forGuard works with assignRole for non-default guard', function () {
    Event::fake([RolesAssigned::class]);

    $apiRole = Role::create(['name' => 'api-editor', 'guard_name' => 'api']);

    $this->user->forGuard('api')->assignRole('api-editor');

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'    => $apiRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
});

test('forGuard works with syncRoles for non-default guard', function () {
    Event::fake([RolesAssigned::class]);

    $apiAdmin = Role::create(['name' => 'admin', 'guard_name' => 'api']);
    $apiEditor = Role::create(['name' => 'editor', 'guard_name' => 'api']);

    // Assign api admin first
    $this->user->forGuard('api')->assignRole('admin');

    // Sync to only api editor
    $this->user->forGuard('api')->syncRoles(['editor']);

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id'  => $apiAdmin->id,
        'model_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'  => $apiEditor->id,
        'model_id' => $this->user->id,
    ]);
});

test('forGuard works with removeRole for non-default guard', function () {
    Event::fake([RolesAssigned::class]);

    $apiRole = Role::create(['name' => 'api-admin', 'guard_name' => 'api']);

    $this->user->forGuard('api')->assignRole('api-admin');
    $this->user->forGuard('api')->removeRole('api-admin');

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id'  => $apiRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('forGuard works with givePermissionTo for non-default guard', function () {
    $apiPerm = Permission::create(['name' => 'api.access', 'guard_name' => 'api']);

    $this->user->forGuard('api')->givePermissionTo('api.access');

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $apiPerm->id,
        'model_id'      => $this->user->id,
        'model_type'    => User::class,
    ]);
});

test('forGuard works with revokePermissionTo for non-default guard', function () {
    $apiPerm = Permission::create(['name' => 'api.access', 'guard_name' => 'api']);

    $this->user->forGuard('api')->givePermissionTo('api.access');
    $this->user->forGuard('api')->revokePermissionTo('api.access');

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $apiPerm->id,
        'model_id'      => $this->user->id,
    ]);
});

test('forGuard works with syncPermissions for non-default guard', function () {
    $apiPerm1 = Permission::create(['name' => 'api.read', 'guard_name' => 'api']);
    $apiPerm2 = Permission::create(['name' => 'api.write', 'guard_name' => 'api']);

    $this->user->forGuard('api')->givePermissionTo('api.read');
    $this->user->forGuard('api')->syncPermissions(['api.write']);

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $apiPerm1->id,
        'model_id'      => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $apiPerm2->id,
        'model_id'      => $this->user->id,
    ]);
});

test('forGuard does not affect subsequent operations', function () {
    Event::fake([RolesAssigned::class]);

    $apiRole = Role::create(['name' => 'api-viewer', 'guard_name' => 'api']);

    // First call uses api guard
    $this->user->forGuard('api')->assignRole('api-viewer');

    // Second call without forGuard should use default (web)
    $this->user->assignRole('editor');

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'  => $apiRole->id,
        'model_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'  => $this->editorRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('hasAnyRole accepts a Collection and flattens it correctly', function () {
    expect($this->user->hasAnyRole(collect(['editor', 'admin'])))->toBeTrue()
        ->and($this->user->hasAnyRole(collect(['editor', 'viewer'])))->toBeFalse();
});

test('hasAllRoles accepts a Collection', function () {
    DB::table('model_has_roles')->insert([
        'role_id'    => $this->editorRole->id,
        'model_id'   => $this->user->id,
        'model_type' => User::class,
    ]);
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    expect($this->user->hasAllRoles(collect(['admin', 'editor'])))->toBeTrue()
        ->and($this->user->hasAllRoles(collect(['admin', 'nonexistent'])))->toBeFalse();
});

test('assignRole with integer ID silently drops IDs that do not belong to the guard', function () {
    Event::fake([RolesAssigned::class]);

    $apiRole = Role::create(['name' => 'api-admin', 'guard_name' => 'api']);

    // Caller is on default 'web' guard, but passes an API role's ID.
    // The guard mismatch should cause the ID to be dropped silently.
    $this->user->assignRole($apiRole->id);

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id'  => $apiRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('assignRole with integer ID on the correct guard still works', function () {
    Event::fake([RolesAssigned::class]);

    $apiRole = Role::create(['name' => 'api-editor', 'guard_name' => 'api']);

    $this->user->forGuard('api')->assignRole($apiRole->id);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id'  => $apiRole->id,
        'model_id' => $this->user->id,
    ]);
});

test('scopeRole with integer ID filters by guard and excludes cross-guard matches', function () {
    // Create a web role and an api role with the SAME name but different guards.
    $apiAdmin = Role::create(['name' => 'admin', 'guard_name' => 'api']);

    $user2 = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);
    DB::table('model_has_roles')->insert([
        'role_id' => $apiAdmin->id, 'model_id' => $user2->id, 'model_type' => User::class,
    ]);

    // Scope by the API admin's ID but with guard 'web' — should NOT match anyone.
    $webQuery = User::query()->role($apiAdmin->id, 'web')->get();

    expect($webQuery)->toHaveCount(0);

    $apiQuery = User::query()->role($apiAdmin->id, 'api')->get();

    expect($apiQuery->pluck('id')->all())->toBe([$user2->id]);
});

test('scopePermission with integer ID filters by guard', function () {
    $apiPerm = Permission::create(['name' => 'users.create', 'guard_name' => 'api']);

    $user2 = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);
    DB::table('model_has_permissions')->insert([
        'permission_id' => $apiPerm->id, 'model_id' => $user2->id, 'model_type' => User::class,
    ]);

    expect(User::query()->permission($apiPerm->id, 'web')->get())->toHaveCount(0)
        ->and(User::query()->permission($apiPerm->id, 'api')->get()->pluck('id')->all())->toBe([$user2->id]);
});

test('roleIdNameCache is flushed when a role is renamed', function () {
    // Pre-warm the static cache by looking up the role by ID.
    $this->user->hasRole($this->adminRole->id);

    // Rename the role in the DB through Eloquent (triggers the saved hook).
    $this->adminRole->update(['name' => 'renamed-admin']);

    // Seed the repo so the user has the NEW name.
    $this->repo->setUserRoles($this->user->id, ['web|renamed-admin']);

    // Second lookup by ID should see the renamed role, not a stale 'admin'.
    expect($this->user->hasRole($this->adminRole->id))->toBeTrue();
});
