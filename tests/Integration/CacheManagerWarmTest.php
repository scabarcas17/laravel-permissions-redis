<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->manager = new AuthorizationCacheManager($this->repo);
});

function seedDbData(): array
{
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $permId = DB::table('permissions')->insertGetId([
        'name' => 'users.create',
        'guard_name' => 'web',
    ]);

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'admin',
        'guard_name' => 'web',
    ]);

    DB::table('role_has_permissions')->insert([
        'role_id' => $roleId,
        'permission_id' => $permId,
    ]);

    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_id' => $user->id,
        'model_type' => User::class,
    ]);

    return ['user' => $user, 'roleId' => $roleId, 'permId' => $permId];
}

test('warmUser computes role-inherited permissions from database', function () {
    $data = seedDbData();

    $this->manager->warmUser($data['user']->id);

    expect($this->repo->getUserPermissions($data['user']->id))->toContain('users.create')
        ->and($this->repo->getUserRoles($data['user']->id))->toContain('admin');
});

test('warmUser includes direct permissions', function () {
    $data = seedDbData();

    $directPermId = DB::table('permissions')->insertGetId([
        'name' => 'special.access',
        'guard_name' => 'web',
    ]);

    DB::table('model_has_permissions')->insert([
        'permission_id' => $directPermId,
        'model_id' => $data['user']->id,
        'model_type' => User::class,
    ]);

    $this->manager->warmUser($data['user']->id);

    $permissions = $this->repo->getUserPermissions($data['user']->id);

    expect($permissions)->toContain('users.create')
        ->and($permissions)->toContain('special.access');
});

test('warmUser deduplicates permissions from roles and direct', function () {
    $data = seedDbData();

    // Same permission assigned directly too
    DB::table('model_has_permissions')->insert([
        'permission_id' => $data['permId'],
        'model_id' => $data['user']->id,
        'model_type' => User::class,
    ]);

    $this->manager->warmUser($data['user']->id);

    $permissions = $this->repo->getUserPermissions($data['user']->id);

    // Should have no duplicates
    expect($permissions)->toHaveCount(1)
        ->and($permissions)->toContain('users.create');
});

test('warmRole sets role permissions and user reverse index', function () {
    $data = seedDbData();

    $this->manager->warmRole($data['roleId']);

    expect($this->repo->getRoleUserIds($data['roleId']))->toContain($data['user']->id);
});

test('warmAll processes all roles and users', function () {
    $data = seedDbData();

    $user2 = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);

    DB::table('model_has_roles')->insert([
        'role_id' => $data['roleId'],
        'model_id' => $user2->id,
        'model_type' => User::class,
    ]);

    $this->manager->warmAll();

    expect($this->repo->getUserPermissions($data['user']->id))->toContain('users.create')
        ->and($this->repo->getUserPermissions($user2->id))->toContain('users.create')
        ->and($this->repo->getUserRoles($data['user']->id))->toContain('admin')
        ->and($this->repo->getUserRoles($user2->id))->toContain('admin');
});

test('warmAll flushes before warming', function () {
    $data = seedDbData();

    $this->repo->setUserPermissions(999, ['stale.data']);

    $this->manager->warmAll();

    expect($this->repo->getUserPermissions(999))->toBe([]);
});

test('evictUser removes user cache', function () {
    $this->repo->setUserPermissions(1, ['test']);
    $this->repo->setUserRoles(1, ['admin']);

    $this->manager->evictUser(1);

    expect($this->repo->getUserPermissions(1))->toBe([])
        ->and($this->repo->getUserRoles(1))->toBe([])
        ->and($this->repo->userCacheExists(1))->toBeFalse();
});

test('evictRole removes role cache', function () {
    $this->repo->setRolePermissions(1, ['test']);
    $this->repo->setRoleUsers(1, [1, 2]);

    $this->manager->evictRole(1);

    expect($this->repo->getRoleUserIds(1))->toBe([]);
});
