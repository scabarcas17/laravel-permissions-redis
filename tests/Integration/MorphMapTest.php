<?php

declare(strict_types=1);

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Testing\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\AdminUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));

    $this->role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $this->permission = Permission::create(['name' => 'posts.edit', 'guard_name' => 'web']);
    $this->user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
});

// The morph map is static on Relation and outlives the application
// instance, so it must not leak into the other test files.
afterEach(function () {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

test('without use_morph_map the class name is written even when a morph map is defined', function () {
    Relation::morphMap(['user' => User::class]);

    $this->user->assignRole('editor')->givePermissionTo('posts.edit');

    expect(DB::table('model_has_roles')->where('model_id', $this->user->id)->value('model_type'))->toBe(User::class)
        ->and(DB::table('model_has_permissions')->where('model_id', $this->user->id)->value('model_type'))->toBe(User::class)
        ->and($this->user->roles()->count())->toBe(1)
        ->and($this->user->hasRole('editor'))->toBeTrue();
});

test('with use_morph_map the alias is written and read by the relations, the scopes and the cache warmer', function () {
    config()->set('permissions-redis.use_morph_map', true);
    Relation::morphMap(['user' => User::class]);

    $this->user->assignRole('editor')->givePermissionTo('posts.edit');

    expect(DB::table('model_has_roles')->where('model_id', $this->user->id)->value('model_type'))->toBe('user')
        ->and(DB::table('model_has_permissions')->where('model_id', $this->user->id)->value('model_type'))->toBe('user')
        ->and($this->user->roles()->pluck('name')->all())->toBe(['editor'])
        ->and($this->user->getDirectPermissions()->pluck('name')->all())->toBe(['posts.edit'])
        ->and(User::role('editor')->pluck('id')->all())->toBe([$this->user->id])
        ->and(User::permission('posts.edit')->pluck('id')->all())->toBe([$this->user->id])
        // Written by the RolesAssigned / PermissionsAssigned listeners.
        ->and($this->repo->getUserRoles($this->user->id))->toBe(['web|editor'])
        ->and($this->repo->getUserPermissions($this->user->id))->toContain('web|posts.edit')
        ->and($this->repo->getRoleUserIds($this->role->id))->toBe([$this->user->id])
        ->and($this->user->hasRole('editor'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.edit'))->toBeTrue();
});

test('a full warm reads alias rows under use_morph_map', function () {
    config()->set('permissions-redis.use_morph_map', true);
    Relation::morphMap(['user' => User::class]);

    DB::table('model_has_roles')->insert([
        'role_id' => $this->role->id, 'model_id' => $this->user->id, 'model_type' => 'user',
    ]);
    DB::table('role_has_permissions')->insert([
        'role_id' => $this->role->id, 'permission_id' => $this->permission->id,
    ]);

    $manager = app(AuthorizationCacheManager::class);
    $manager->warmAll();

    expect($this->repo->getUserRoles($this->user->id))->toBe(['web|editor'])
        ->and($this->repo->getUserPermissions($this->user->id))->toBe(['web|posts.edit'])
        ->and($this->repo->getRoleUserIds($this->role->id))->toBe([$this->user->id])
        ->and($manager->getUserIdsAffectedByPermission($this->permission->id))->toBe([$this->user->id]);
});

test('with use_morph_map and no morph map the class name is still used', function () {
    config()->set('permissions-redis.use_morph_map', true);

    $this->user->assignRole('editor');

    expect(DB::table('model_has_roles')->where('model_id', $this->user->id)->value('model_type'))->toBe(User::class)
        ->and($this->user->roles()->count())->toBe(1);
});

test('Role::users() writes and reads the alias under use_morph_map', function () {
    config()->set('permissions-redis.use_morph_map', true);
    Relation::morphMap(['user' => User::class]);

    $this->role->users()->attach($this->user->id);

    expect(DB::table('model_has_roles')->where('role_id', $this->role->id)->value('model_type'))->toBe('user')
        ->and($this->role->users()->pluck('id')->all())->toBe([$this->user->id]);
});

test('each configured model uses its own alias', function () {
    config()->set('permissions-redis.user_model', [User::class, AdminUser::class]);
    config()->set('permissions-redis.use_morph_map', true);
    Relation::morphMap(['user' => User::class, 'admin' => AdminUser::class]);

    $admin = AdminUser::create(['name' => 'Root', 'email' => 'root@test.com']);

    $this->user->assignRole('editor');
    $admin->assignRole('editor');

    expect(DB::table('model_has_roles')->orderBy('model_type')->pluck('model_type', 'model_id')->all())
        ->toBe([$admin->id => 'admin', $this->user->id => 'user'])
        ->and(User::role('editor')->pluck('id')->all())->toBe([$this->user->id])
        ->and(AdminUser::role('editor')->pluck('id')->all())->toBe([$admin->id])
        ->and(app(AuthorizationCacheManager::class)->getRoleUserIdsFromDb($this->role->id))
        ->toEqualCanonicalizing([$this->user->id, $admin->id]);
});

test('rows written with the class name are not read once use_morph_map is on', function () {
    // This is why the README asks existing installations to rewrite their
    // rows before turning the option on.
    Relation::morphMap(['user' => User::class]);

    DB::table('model_has_roles')->insert([
        'role_id' => $this->role->id, 'model_id' => $this->user->id, 'model_type' => User::class,
    ]);

    config()->set('permissions-redis.use_morph_map', true);
    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    expect($this->user->roles()->count())->toBe(0)
        ->and($this->repo->getUserRoles($this->user->id))->toBe([]);
});

test('an enforced morph map that leaves out a configured model surfaces the Laravel exception', function () {
    config()->set('permissions-redis.use_morph_map', true);
    Relation::enforceMorphMap(['role' => Role::class]);

    expect(fn () => $this->user->roles()->count())->toThrow(ClassMorphViolationException::class);
});
