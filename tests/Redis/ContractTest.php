<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

beforeEach(function () {
    $this->repo = app(PermissionRepositoryInterface::class);
});

// ─── userHasPermission / userHasRole ───

test('set+has permission round trip', function () {
    $this->repo->setUserPermissions(1, ['web|users.create', 'web|users.edit']);

    expect($this->repo->userHasPermission(1, 'web|users.create'))->toBeTrue()
        ->and($this->repo->userHasPermission(1, 'web|users.delete'))->toBeFalse();
});

test('set+has role round trip', function () {
    $this->repo->setUserRoles(2, ['web|admin']);

    expect($this->repo->userHasRole(2, 'web|admin'))->toBeTrue()
        ->and($this->repo->userHasRole(2, 'web|editor'))->toBeFalse();
});

// ─── Empty set sentinel ───

test('empty user permissions populates the cache so userCacheExists returns true', function () {
    $this->repo->setUserPermissions(3, []);

    expect($this->repo->userCacheExists(3))->toBeTrue()
        ->and($this->repo->getUserPermissions(3))->toBe([]);
});

test('getUserPermissions filters the empty sentinel out of returned members', function () {
    $this->repo->setUserPermissions(4, []);
    $this->repo->setUserPermissions(5, ['web|users.create']);

    expect($this->repo->getUserPermissions(4))->toBe([])
        ->and($this->repo->getUserPermissions(5))->toBe(['web|users.create']);
});

// ─── Atomicity of replaceSetBatch ───

test('replaceSetBatch writes multiple sets in a single transaction', function () {
    $this->repo->replaceSetBatch([
        'user:10:permissions' => ['web|a', 'web|b'],
        'user:10:roles'       => ['web|admin'],
    ]);

    expect($this->repo->getUserPermissions(10))->toEqualCanonicalizing(['web|a', 'web|b'])
        ->and($this->repo->getUserRoles(10))->toBe(['web|admin']);
});

test('replaceSetBatch overwrites previous set contents (not append)', function () {
    $this->repo->setUserPermissions(11, ['web|old']);
    $this->repo->replaceSetBatch(['user:11:permissions' => ['web|new']]);

    expect($this->repo->getUserPermissions(11))->toBe(['web|new']);
});

// ─── Role reverse index ───

test('setRoleUsers + getRoleUserIds preserves integer IDs', function () {
    $this->repo->setRoleUsers(42, [1, 2, 3]);

    expect($this->repo->getRoleUserIds(42))->toEqualCanonicalizing([1, 2, 3]);
});

test('setRoleUsers preserves UUID string IDs', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $this->repo->setRoleUsers(43, [$uuid]);

    expect($this->repo->getRoleUserIds(43))->toBe([$uuid]);
});

// ─── deleteUserCache / deleteRoleCache ───

test('deleteUserCache removes both permission and role keys', function () {
    $this->repo->setUserPermissions(20, ['web|x']);
    $this->repo->setUserRoles(20, ['web|admin']);

    $this->repo->deleteUserCache(20);

    expect($this->repo->userCacheExists(20))->toBeFalse();
});

test('deleteRoleCache removes both role permission and users keys', function () {
    $this->repo->setRolePermissions(50, ['web|x']);
    $this->repo->setRoleUsers(50, [1]);

    $this->repo->deleteRoleCache(50);

    expect($this->repo->getRoleUserIds(50))->toBe([]);
});

// ─── flushAll ───

test('flushAll removes every key under the configured prefix', function () {
    $this->repo->setUserPermissions(60, ['web|x']);
    $this->repo->setUserRoles(60, ['web|admin']);
    $this->repo->setRolePermissions(61, ['web|y']);

    $this->repo->flushAll();

    expect($this->repo->userCacheExists(60))->toBeFalse()
        ->and($this->repo->getRoleUserIds(61))->toBe([]);
});

// ─── Permission groups (HSET since Redis 4.0) ───

test('setPermissionGroups stores non-null groups and deletes nulls in a single transaction', function () {
    $this->repo->setPermissionGroups([
        'web|a' => 'Alpha',
        'web|b' => 'Beta',
    ]);

    expect($this->repo->getPermissionGroups(['web|a', 'web|b', 'web|missing']))
        ->toBe(['web|a' => 'Alpha', 'web|b' => 'Beta', 'web|missing' => null]);

    $this->repo->setPermissionGroups(['web|a' => null]);

    expect($this->repo->getPermissionGroups(['web|a', 'web|b']))
        ->toBe(['web|a' => null, 'web|b' => 'Beta']);
});

test('replacePermissionGroups atomically rebuilds the hash, dropping previous entries', function () {
    $this->repo->setPermissionGroups([
        'web|a' => 'Alpha',
        'web|b' => 'Beta',
        'web|c' => 'Gamma',
    ]);

    $this->repo->replacePermissionGroups([
        'web|a' => 'Alpha2',
        'web|d' => 'Delta',
    ]);

    expect($this->repo->getPermissionGroups(['web|a', 'web|b', 'web|c', 'web|d']))
        ->toBe([
            'web|a' => 'Alpha2',
            'web|b' => null,
            'web|c' => null,
            'web|d' => 'Delta',
        ]);
});

test('deletePermissionGroup removes a single field', function () {
    $this->repo->setPermissionGroups(['web|a' => 'Alpha', 'web|b' => 'Beta']);

    $this->repo->deletePermissionGroup('web|a');

    expect($this->repo->getPermissionGroups(['web|a', 'web|b']))
        ->toBe(['web|a' => null, 'web|b' => 'Beta']);
});

// ─── TTL is set ───

test('replaceSet applies TTL so keys expire', function () {
    config()->set('permissions-redis.ttl', 3600);
    /** @var \Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository $repo */
    $repo = $this->repo;
    $repo->resetState();

    $repo->setUserPermissions(70, ['web|x']);

    /** @var \Illuminate\Redis\Connections\Connection $connection */
    $connection = \Illuminate\Support\Facades\Redis::connection('default');
    $ttl = (int) $connection->command('ttl', [\Scabarcas\LaravelPermissionsRedis\Tests\Redis\TestCase::TEST_PREFIX . 'user:70:permissions']);

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);
});
