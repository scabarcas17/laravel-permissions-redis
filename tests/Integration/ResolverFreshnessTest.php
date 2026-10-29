<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Testing\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

// The resolver keeps what it read for a user in memory for the rest of the
// process, so whatever rewrites that user's sets from this process has to
// drop the copy, or the next check answers from before the change.

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));

    $this->role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $this->view = Permission::create(['name' => 'posts.view', 'guard_name' => 'web']);
    $this->edit = Permission::create(['name' => 'posts.edit', 'guard_name' => 'web']);

    $this->user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $this->user->assignRole('editor');
});

test('a permission given to a role reaches a user already checked for it', function () {
    expect($this->user->hasPermissionTo('posts.edit'))->toBeFalse();

    $this->role->givePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue();
});

test('a permission given to a role reaches a user already checked for other permissions', function () {
    expect($this->user->hasPermissionTo('posts.view'))->toBeFalse();

    $this->role->givePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.view'))->toBeFalse();
});

test('a permission revoked from a role stops granting it to a user already checked', function () {
    $this->role->givePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue();

    $this->role->revokePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeFalse();
});

test('deleting a role takes it from a user already checked', function () {
    expect($this->user->hasRole('editor'))->toBeTrue();

    $this->role->delete();

    // Asserted through the role: the rewarm reads roles through the roles
    // table, while the SQLite test connection keeps the pivot rows a foreign
    // key cascade removes on MySQL or PostgreSQL.
    expect($this->user->hasRole('editor'))->toBeFalse();
});

test('renaming a permission moves a user already checked to the new name', function () {
    $this->role->givePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue();

    $this->edit->update(['name' => 'posts.update']);

    expect($this->user->hasPermissionTo('posts.update'))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.edit'))->toBeFalse();
});

test('warming a user drops what the resolver read for that user', function () {
    expect($this->user->hasPermissionTo('posts.edit'))->toBeFalse();

    // A change made behind the package's back, then an explicit warm.
    DB::table('model_has_permissions')->insert([
        'permission_id' => $this->edit->id,
        'model_type'    => User::class,
        'model_id'      => $this->user->id,
    ]);

    app(AuthorizationCacheManager::class)->warmUser($this->user->id);

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue();
});

test('evicting a user drops what the resolver read for that user', function () {
    $this->role->givePermissionTo('posts.edit');

    expect($this->user->hasPermissionTo('posts.edit'))->toBeTrue();

    DB::table('model_has_roles')->where('model_id', $this->user->id)->delete();
    app(AuthorizationCacheManager::class)->evictUser($this->user->id);

    // The next check misses Redis, warms from the database and sees the change.
    expect($this->user->hasPermissionTo('posts.edit'))->toBeFalse();
});
