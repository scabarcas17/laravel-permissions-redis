<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;
use Scabarcas\LaravelPermissionsRedis\Testing\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\AdminUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\DualTraitUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\SoftDeletingUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($this->repo));

    $this->role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $this->permission = Permission::create(['name' => 'posts.edit', 'guard_name' => 'web']);

    $this->assignmentRows = fn (int $userId, string $modelType): int => DB::table('model_has_roles')
        ->where('model_id', $userId)->where('model_type', $modelType)->count()
        + DB::table('model_has_permissions')
            ->where('model_id', $userId)->where('model_type', $modelType)->count();
});

test('a hard delete removes the user role and permission rows and nobody else\'s', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $other = User::create(['name' => 'Bob', 'email' => 'bob@test.com']);

    $user->assignRole('editor')->givePermissionTo('posts.edit');
    $other->assignRole('editor')->givePermissionTo('posts.edit');

    $user->delete();

    expect(($this->assignmentRows)($user->id, User::class))->toBe(0)
        ->and(($this->assignmentRows)($other->id, User::class))->toBe(2);
});

test('a hard delete leaves the rows of another model that shares the id', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);

    DB::table('model_has_roles')->insert([
        'role_id' => $this->role->id, 'model_id' => $user->id, 'model_type' => AdminUser::class,
    ]);

    $user->assignRole('editor');
    $user->delete();

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->pluck('model_type')->all())
        ->toBe([AdminUser::class]);
});

test('a hard delete evicts the user from Redis and from the role indexes', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor');

    expect($this->repo->userCacheExists($user->id))->toBeTrue()
        ->and($this->repo->getRoleUserIds($this->role->id))->toContain($user->id);

    $user->delete();

    expect($this->repo->userCacheExists($user->id))->toBeFalse()
        ->and($this->repo->getRoleUserIds($this->role->id))->not->toContain($user->id);
});

test('a hard delete dispatches UserDeleted once, carrying the roles the user held', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor');

    Event::fake([UserDeleted::class]);

    $user->delete();

    Event::assertDispatchedTimes(UserDeleted::class, 1);
    Event::assertDispatched(
        UserDeleted::class,
        fn (UserDeleted $event): bool => $event->userId === $user->id && $event->affectedRoleIds === [$this->role->id],
    );
});

test('the resolver forgets a deleted user within the same process', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor');

    // Memoised in the resolver's per-process cache.
    expect($user->hasRole('editor'))->toBeTrue();

    $user->delete();

    $sameId = (new User())->forceFill(['id' => $user->id]);

    expect($sameId->hasRole('editor'))->toBeFalse();
});

test('a model that still uses DispatchesPermissionEvents alongside the trait dispatches UserDeleted once', function () {
    $user = DualTraitUser::create(['name' => 'Ann', 'email' => 'ann@test.com']);

    Event::fake([UserDeleted::class]);

    $user->delete();

    Event::assertDispatchedTimes(UserDeleted::class, 1);
});

test('a soft delete keeps the assignments and announces no role changes', function () {
    config()->set('permissions-redis.user_model', [User::class, SoftDeletingUser::class]);

    $user = SoftDeletingUser::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor')->givePermissionTo('posts.edit');

    Event::fake([UserDeleted::class]);

    $user->delete();

    expect($user->trashed())->toBeTrue()
        ->and(($this->assignmentRows)($user->id, SoftDeletingUser::class))->toBe(2);

    Event::assertDispatched(
        UserDeleted::class,
        fn (UserDeleted $event): bool => $event->userId === $user->id && $event->affectedRoleIds === [],
    );
});

test('a soft delete evicts the cache and a restore brings the permissions back from the database', function () {
    config()->set('permissions-redis.user_model', [User::class, SoftDeletingUser::class]);

    $user = SoftDeletingUser::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor');

    $user->delete();

    expect($this->repo->userCacheExists($user->id))->toBeFalse();

    $user->restore();

    expect($user->hasRole('editor'))->toBeTrue();
});

test('a force delete of a soft-deletable user removes the assignments', function () {
    config()->set('permissions-redis.user_model', [User::class, SoftDeletingUser::class]);

    $user = SoftDeletingUser::create(['name' => 'Ann', 'email' => 'ann@test.com']);
    $user->assignRole('editor')->givePermissionTo('posts.edit');

    $user->delete();

    expect(($this->assignmentRows)($user->id, SoftDeletingUser::class))->toBe(2);

    $user->forceDelete();

    expect(($this->assignmentRows)($user->id, SoftDeletingUser::class))->toBe(0)
        ->and($this->repo->getRoleUserIds($this->role->id))->not->toContain($user->id);
});
