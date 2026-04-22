<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;

beforeEach(function () {
    $this->connection = Mockery::mock(Connection::class);
    Redis::shouldReceive('connection')->with('default')->andReturn($this->connection);
    $this->repository = new RedisPermissionRepository();
});

// ─── userHasPermission ───

test('userHasPermission returns true when SISMEMBER returns 1', function () {
    $this->connection->shouldReceive('command')
        ->with('sismember', ['auth:user:1:permissions', 'web|users.create'])
        ->once()
        ->andReturn(1);

    expect($this->repository->userHasPermission(1, 'web|users.create'))->toBeTrue();
});

test('userHasPermission returns false when SISMEMBER returns 0', function () {
    $this->connection->shouldReceive('command')
        ->with('sismember', ['auth:user:1:permissions', 'web|users.create'])
        ->once()
        ->andReturn(0);

    expect($this->repository->userHasPermission(1, 'web|users.create'))->toBeFalse();
});

// ─── userHasRole ───

test('userHasRole returns true when SISMEMBER returns 1', function () {
    $this->connection->shouldReceive('command')
        ->with('sismember', ['auth:user:1:roles', 'web|admin'])
        ->once()
        ->andReturn(1);

    expect($this->repository->userHasRole(1, 'web|admin'))->toBeTrue();
});

test('userHasRole returns false when SISMEMBER returns 0', function () {
    $this->connection->shouldReceive('command')
        ->with('sismember', ['auth:user:1:roles', 'web|admin'])
        ->once()
        ->andReturn(0);

    expect($this->repository->userHasRole(1, 'web|admin'))->toBeFalse();
});

// ─── getUserPermissions ───

test('getUserPermissions returns members excluding empty sentinel', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:user:1:permissions'])
        ->once()
        ->andReturn(['web|users.create', 'web|users.edit', RedisPermissionRepository::EMPTY_SENTINEL]);

    $result = $this->repository->getUserPermissions(1);

    expect($result)->toBe(['web|users.create', 'web|users.edit']);
});

test('getUserPermissions returns empty array when Redis returns null', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:user:1:permissions'])
        ->once()
        ->andReturn(null);

    expect($this->repository->getUserPermissions(1))->toBe([]);
});

// ─── getUserRoles ───

test('getUserRoles returns members excluding empty sentinel', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:user:1:roles'])
        ->once()
        ->andReturn(['web|admin', RedisPermissionRepository::EMPTY_SENTINEL]);

    expect($this->repository->getUserRoles(1))->toBe(['web|admin']);
});

test('getUserRoles returns empty array when Redis returns null', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:user:1:roles'])
        ->once()
        ->andReturn(null);

    expect($this->repository->getUserRoles(1))->toBe([]);
});

// ─── getRoleUserIds ───

test('getRoleUserIds returns integer user IDs excluding empty sentinel', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:role:5:users'])
        ->once()
        ->andReturn(['1', '2', '3', RedisPermissionRepository::EMPTY_SENTINEL]);

    expect($this->repository->getRoleUserIds(5))->toBe([1, 2, 3]);
});

test('getRoleUserIds returns empty array when Redis returns null', function () {
    $this->connection->shouldReceive('command')
        ->with('smembers', ['auth:role:5:users'])
        ->once()
        ->andReturn(null);

    expect($this->repository->getRoleUserIds(5))->toBe([]);
});

// ─── setUserPermissions ───

test('setUserPermissions replaces set with MULTI/EXEC transaction', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:user:1:permissions'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:user:1:permissions', 'web|users.create', 'web|users.edit'])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:permissions', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setUserPermissions(1, ['web|users.create', 'web|users.edit']);
});

test('setUserPermissions uses empty sentinel for empty array', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:user:1:permissions'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:user:1:permissions', RedisPermissionRepository::EMPTY_SENTINEL])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:permissions', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setUserPermissions(1, []);
});

// ─── setUserRoles ───

test('setUserRoles replaces set with MULTI/EXEC transaction', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:user:1:roles'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:user:1:roles', 'web|admin'])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:roles', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setUserRoles(1, ['web|admin']);
});

// ─── setRolePermissions ───

test('setRolePermissions replaces set with MULTI/EXEC transaction', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:role:5:permissions'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:role:5:permissions', 'web|users.create'])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:role:5:permissions', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setRolePermissions(5, ['web|users.create']);
});

// ─── setRoleUsers ───

test('setRoleUsers converts integer IDs to strings', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:role:5:users'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:role:5:users', '1', '2'])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:role:5:users', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setRoleUsers(5, [1, 2]);
});

// ─── userCacheExists ───

test('userCacheExists returns true when EXISTS returns 1', function () {
    $this->connection->shouldReceive('command')
        ->with('exists', ['auth:user:1:permissions'])
        ->once()
        ->andReturn(1);

    expect($this->repository->userCacheExists(1))->toBeTrue();
});

test('userCacheExists returns false when EXISTS returns 0', function () {
    $this->connection->shouldReceive('command')
        ->with('exists', ['auth:user:1:permissions'])
        ->once()
        ->andReturn(0);

    expect($this->repository->userCacheExists(1))->toBeFalse();
});

// ─── deleteUserCache ───

test('deleteUserCache deletes both permissions and roles keys', function () {
    $this->connection->shouldReceive('command')
        ->with('del', ['auth:user:1:permissions', 'auth:user:1:roles'])
        ->once();

    $this->repository->deleteUserCache(1);
});

// ─── deleteRoleCache ───

test('deleteRoleCache deletes both permissions and users keys', function () {
    $this->connection->shouldReceive('command')
        ->with('del', ['auth:role:5:permissions', 'auth:role:5:users'])
        ->once();

    $this->repository->deleteRoleCache(5);
});

// ─── flushAll ───

test('flushAll scans and deletes all keys with prefix', function () {
    // First scan returns some keys and cursor '42'
    $this->connection->shouldReceive('command')
        ->with('scan', ['0', 'match', 'auth:*', 'count', 100])
        ->once()
        ->andReturn(['42', ['auth:user:1:permissions', 'auth:user:1:roles']]);

    $this->connection->shouldReceive('command')
        ->with('del', ['auth:user:1:permissions', 'auth:user:1:roles'])
        ->once();

    // Second scan returns empty and cursor '0' (done)
    $this->connection->shouldReceive('command')
        ->with('scan', ['42', 'match', 'auth:*', 'count', 100])
        ->once()
        ->andReturn(['0', []]);

    $this->repository->flushAll();
});

test('flushAll handles empty scan result', function () {
    $this->connection->shouldReceive('command')
        ->with('scan', ['0', 'match', 'auth:*', 'count', 100])
        ->once()
        ->andReturn(['0', []]);

    // DEL should NOT be called since there are no keys
    $this->connection->shouldNotReceive('command')->with('del', Mockery::any());

    $this->repository->flushAll();
});

// ─── custom config ───

test('uses configured prefix for keys', function () {
    config()->set('permissions-redis.prefix', 'custom:');

    $this->connection->shouldReceive('command')
        ->with('sismember', ['custom:user:1:permissions', 'web|test'])
        ->once()
        ->andReturn(1);

    expect($this->repository->userHasPermission(1, 'web|test'))->toBeTrue();
});

test('uses configured TTL for expiry', function () {
    config()->set('permissions-redis.ttl', 3600);

    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('sadd', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:permissions', 3600])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->setUserPermissions(1, ['web|test']);
});

// ─── EXEC validation ───

test('setUserPermissions throws TransactionFailedException when EXEC returns null', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('sadd', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('expire', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn(null);

    expect(fn () => $this->repository->setUserPermissions(1, ['web|test']))
        ->toThrow(\Scabarcas\LaravelPermissionsRedis\Exceptions\TransactionFailedException::class);
});

test('setUserPermissions throws TransactionFailedException when EXEC returns false', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('sadd', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('expire', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn(false);

    expect(fn () => $this->repository->setUserPermissions(1, ['web|test']))
        ->toThrow(\Scabarcas\LaravelPermissionsRedis\Exceptions\TransactionFailedException::class);
});

test('replaceSetBatch throws TransactionFailedException when EXEC returns null', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', Mockery::any())->zeroOrMoreTimes();
    $this->connection->shouldReceive('command')->with('sadd', Mockery::any())->zeroOrMoreTimes();
    $this->connection->shouldReceive('command')->with('expire', Mockery::any())->zeroOrMoreTimes();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn(null);

    expect(fn () => $this->repository->replaceSetBatch(['user:1:permissions' => ['web|x']]))
        ->toThrow(\Scabarcas\LaravelPermissionsRedis\Exceptions\TransactionFailedException::class);
});

test('replaceSetBatch succeeds when EXEC returns an array', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:user:1:permissions'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:user:1:permissions', 'web|users.create'])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:permissions', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->replaceSetBatch(['user:1:permissions' => ['web|users.create']]);
});

test('replaceSetBatch uses empty sentinel for empty member arrays', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('del', ['auth:user:1:permissions'])->once();
    $this->connection->shouldReceive('command')->with('sadd', ['auth:user:1:permissions', RedisPermissionRepository::EMPTY_SENTINEL])->once();
    $this->connection->shouldReceive('command')->with('expire', ['auth:user:1:permissions', 86400])->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1, 1]);

    $this->repository->replaceSetBatch(['user:1:permissions' => []]);
});

test('replaceSetBatch returns early when sets is empty', function () {
    $this->connection->shouldNotReceive('command');

    $this->repository->replaceSetBatch([]);
});

// ─── Permission groups ───

test('setPermissionGroups stores non-null groups via HMSET', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')
        ->with('hmset', ['auth:permission_groups', [
            'web|users.create' => 'User Management',
            'web|posts.edit'   => 'Content',
        ]])
        ->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1]);

    $this->repository->setPermissionGroups([
        'web|users.create' => 'User Management',
        'web|posts.edit'   => 'Content',
    ]);
});

test('setPermissionGroups deletes fields with null group value via HDEL', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')
        ->with('hdel', ['auth:permission_groups', 'web|users.create'])
        ->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1]);

    $this->repository->setPermissionGroups(['web|users.create' => null]);
});

test('setPermissionGroups combines HMSET and HDEL in a single transaction', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')
        ->with('hmset', ['auth:permission_groups', ['web|posts.edit' => 'Content']])
        ->once();
    $this->connection->shouldReceive('command')
        ->with('hdel', ['auth:permission_groups', 'web|users.create'])
        ->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn([1, 1]);

    $this->repository->setPermissionGroups([
        'web|posts.edit'   => 'Content',
        'web|users.create' => null,
    ]);
});

test('setPermissionGroups throws TransactionFailedException when EXEC returns false', function () {
    $this->connection->shouldReceive('command')->with('multi')->once();
    $this->connection->shouldReceive('command')->with('hmset', Mockery::any())->once();
    $this->connection->shouldReceive('command')->with('exec')->once()->andReturn(false);

    expect(fn () => $this->repository->setPermissionGroups(['web|x' => 'G']))
        ->toThrow(\Scabarcas\LaravelPermissionsRedis\Exceptions\TransactionFailedException::class);
});

test('setPermissionGroups returns early when groups is empty', function () {
    $this->connection->shouldNotReceive('command');

    $this->repository->setPermissionGroups([]);
});

test('getPermissionGroups fetches via HMGET and maps nulls', function () {
    $this->connection->shouldReceive('command')
        ->with('hmget', ['auth:permission_groups', 'web|users.create', 'web|posts.edit'])
        ->once()
        ->andReturn(['User Management', null]);

    $result = $this->repository->getPermissionGroups(['web|users.create', 'web|posts.edit']);

    expect($result)->toBe([
        'web|users.create' => 'User Management',
        'web|posts.edit'   => null,
    ]);
});

test('getPermissionGroups returns empty array for empty input', function () {
    $this->connection->shouldNotReceive('command');

    expect($this->repository->getPermissionGroups([]))->toBe([]);
});

test('getPermissionGroups treats empty string as null', function () {
    $this->connection->shouldReceive('command')
        ->with('hmget', ['auth:permission_groups', 'web|x'])
        ->once()
        ->andReturn(['']);

    expect($this->repository->getPermissionGroups(['web|x']))->toBe(['web|x' => null]);
});

test('deletePermissionGroup removes single field via HDEL', function () {
    $this->connection->shouldReceive('command')
        ->with('hdel', ['auth:permission_groups', 'web|users.create'])
        ->once();

    $this->repository->deletePermissionGroup('web|users.create');
});
