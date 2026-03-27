<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->manager = new AuthorizationCacheManager($this->repo);
});

function seedDbData(): array
{
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $permId = DB::table('permissions')->insertGetId([
        'name'       => 'users.create',
        'guard_name' => 'web',
    ]);

    $roleId = DB::table('roles')->insertGetId([
        'name'       => 'admin',
        'guard_name' => 'web',
    ]);

    DB::table('role_has_permissions')->insert([
        'role_id'       => $roleId,
        'permission_id' => $permId,
    ]);

    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    return ['user' => $user, 'roleId' => $roleId, 'permId' => $permId];
}

test('warmUser computes role-inherited permissions from database', function () {
    $data = seedDbData();

    $this->manager->warmUser($data['user']->id);

    expect($this->repo->getUserPermissions($data['user']->id))->toContain('web|users.create')
        ->and($this->repo->getUserRoles($data['user']->id))->toContain('web|admin');
});

test('warmUser includes direct permissions', function () {
    $data = seedDbData();

    $directPermId = DB::table('permissions')->insertGetId([
        'name'       => 'special.access',
        'guard_name' => 'web',
    ]);

    DB::table('model_has_permissions')->insert([
        'permission_id' => $directPermId,
        'model_id'      => $data['user']->id,
        'model_type'    => User::class,
    ]);

    $this->manager->warmUser($data['user']->id);

    $permissions = $this->repo->getUserPermissions($data['user']->id);

    expect($permissions)->toContain('web|users.create')
        ->and($permissions)->toContain('web|special.access');
});

test('warmUser deduplicates permissions from roles and direct', function () {
    $data = seedDbData();

    // Same permission assigned directly too
    DB::table('model_has_permissions')->insert([
        'permission_id' => $data['permId'],
        'model_id'      => $data['user']->id,
        'model_type'    => User::class,
    ]);

    $this->manager->warmUser($data['user']->id);

    $permissions = $this->repo->getUserPermissions($data['user']->id);

    // Should have no duplicates
    expect($permissions)->toHaveCount(1)
        ->and($permissions)->toContain('web|users.create');
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
        'role_id'    => $data['roleId'],
        'model_id'   => $user2->id,
        'model_type' => User::class,
    ]);

    $this->manager->warmAll();

    expect($this->repo->getUserPermissions($data['user']->id))->toContain('web|users.create')
        ->and($this->repo->getUserPermissions($user2->id))->toContain('web|users.create')
        ->and($this->repo->getUserRoles($data['user']->id))->toContain('web|admin')
        ->and($this->repo->getUserRoles($user2->id))->toContain('web|admin');
});

test('warmAll flushes before warming', function () {
    $data = seedDbData();

    $this->repo->setUserPermissions(999, ['stale.data']);

    $this->manager->warmAll();

    expect($this->repo->getUserPermissions(999))->toBe([]);
});

test('rewarmAll warms without flushing', function () {
    $data = seedDbData();

    $this->repo->setUserPermissions(999, ['stale.data']);

    $this->manager->rewarmAll();

    // Stale data should still exist (no flush)
    expect($this->repo->getUserPermissions(999))->toContain('stale.data')
        // But real users should be warmed
        ->and($this->repo->getUserPermissions($data['user']->id))->toContain('web|users.create');
});

test('warmUser encodes permissions from multiple guards', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $webPermId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);
    $apiPermId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'api']);

    $webRoleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $apiRoleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'api']);

    DB::table('role_has_permissions')->insert([
        ['role_id' => $webRoleId, 'permission_id' => $webPermId],
        ['role_id' => $apiRoleId, 'permission_id' => $apiPermId],
    ]);

    DB::table('model_has_roles')->insert([
        ['role_id' => $webRoleId, 'model_id' => $user->id, 'model_type' => User::class],
        ['role_id' => $apiRoleId, 'model_id' => $user->id, 'model_type' => User::class],
    ]);

    $this->manager->warmUser($user->id);

    $permissions = $this->repo->getUserPermissions($user->id);
    $roles = $this->repo->getUserRoles($user->id);

    expect($permissions)->toContain('web|users.create')
        ->and($permissions)->toContain('api|users.create')
        ->and($roles)->toContain('web|admin')
        ->and($roles)->toContain('api|admin');
});

test('encodeValue rejects pipe in guard name', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    // Insert a permission with pipe in guard_name to trigger the validation
    $permId = DB::table('permissions')->insertGetId([
        'name'       => 'test.perm',
        'guard_name' => 'web|api',
    ]);

    DB::table('model_has_permissions')->insert([
        'permission_id' => $permId,
        'model_id'      => $user->id,
        'model_type'    => User::class,
    ]);

    expect(fn () => $this->manager->warmUser($user->id))
        ->toThrow(InvalidArgumentException::class, 'pipe separator');
});

test('warmAll logs to custom channel when configured', function () {
    config()->set('permissions-redis.log_channel', 'custom');

    $logChannel = Mockery::mock();
    $logChannel->shouldReceive('info')
        ->withArgs(fn (string $msg) => str_contains($msg, '[permissions-redis]'))
        ->atLeast()->once();

    Log::shouldReceive('channel')->with('custom')->andReturn($logChannel);

    $data = seedDbData();
    $this->manager->warmAll();
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
