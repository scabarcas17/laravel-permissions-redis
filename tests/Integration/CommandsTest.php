<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Jobs\WarmAllCacheJob;
use Scabarcas\LaravelPermissionsRedis\Jobs\WarmUserCacheJob;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, function () {
        return new AuthorizationCacheManager($this->repo);
    });
});

test('permissions-redis:warm warms all users and roles', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:warm')
        ->expectsOutputToContain('Warming authorization cache')
        ->expectsOutputToContain('warmed successfully')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions($user->id))->toContain('web|users.create')
        ->and($this->repo->getUserRoles($user->id))->toContain('web|admin');
});

test('permissions-redis:warm-user warms specific user', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'editor', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'posts.edit', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    $this->artisan("permissions-redis:warm-user {$user->id}")
        ->expectsOutputToContain("Warming authorization cache for user {$user->id}")
        ->assertSuccessful();

    expect($this->repo->getUserPermissions($user->id))->toContain('web|posts.edit');
});

test('permissions-redis:warm with --no-flush preserves existing cache', function () {
    // Pre-populate some cache data
    $this->repo->setUserPermissions(999, ['stale.data']);

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:warm --no-flush')
        ->expectsOutputToContain('Rewarming authorization cache (no flush)')
        ->expectsOutputToContain('warmed successfully')
        ->assertSuccessful();

    // Stale data preserved (no flush happened)
    expect($this->repo->getUserPermissions(999))->toContain('stale.data')
        // Real user warmed
        ->and($this->repo->getUserPermissions($user->id))->toContain('web|users.create');
});

test('permissions-redis:flush clears all cache when confirmed', function () {
    $this->repo->setUserPermissions(1, ['test.perm']);
    $this->repo->setUserRoles(1, ['admin']);

    $this->artisan('permissions-redis:flush')
        ->expectsConfirmation('This will remove all authorization cache entries. Continue?', 'yes')
        ->expectsOutputToContain('Authorization cache flushed')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions(1))->toBe([]);
});

test('permissions-redis:flush aborts when not confirmed', function () {
    $this->repo->setUserPermissions(1, ['test.perm']);

    $this->artisan('permissions-redis:flush')
        ->expectsConfirmation('This will remove all authorization cache entries. Continue?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions(1))->toContain('test.perm');
});

test('permissions-redis:warm --queue dispatches WarmAllCacheJob', function () {
    Bus::fake();

    $this->artisan('permissions-redis:warm --queue')
        ->expectsOutputToContain('Queued authorization cache warm')
        ->assertSuccessful();

    Bus::assertDispatched(WarmAllCacheJob::class, fn (WarmAllCacheJob $job): bool => $job->flush === true);
});

test('permissions-redis:warm --queue --no-flush dispatches WarmAllCacheJob with flush=false', function () {
    Bus::fake();

    $this->artisan('permissions-redis:warm --queue --no-flush')
        ->expectsOutputToContain('Queued authorization cache rewarm')
        ->assertSuccessful();

    Bus::assertDispatched(WarmAllCacheJob::class, fn (WarmAllCacheJob $job): bool => $job->flush === false);
});

test('permissions-redis:warm --queue with connection dispatches on that connection', function () {
    Bus::fake();

    $this->artisan('permissions-redis:warm --queue=redis')
        ->assertSuccessful();

    Bus::assertDispatched(WarmAllCacheJob::class, fn (WarmAllCacheJob $job): bool => $job->connection === 'redis');
});

test('permissions-redis:warm-user --queue dispatches WarmUserCacheJob', function () {
    Bus::fake();

    $this->artisan('permissions-redis:warm-user 42 --queue')
        ->expectsOutputToContain('Queued authorization cache warm for user 42')
        ->assertSuccessful();

    Bus::assertDispatched(WarmUserCacheJob::class, fn (WarmUserCacheJob $job): bool => $job->userId === 42);
});

test('permissions-redis:warm-user --queue=sqs dispatches on given connection', function () {
    Bus::fake();

    $this->artisan('permissions-redis:warm-user 7 --queue=sqs')
        ->assertSuccessful();

    Bus::assertDispatched(WarmUserCacheJob::class, fn (WarmUserCacheJob $job): bool => $job->connection === 'sqs');
});

test('WarmUserCacheJob warms the user via AuthorizationCacheManager', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'posts.publish', 'guard_name' => 'web']);

    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_id'   => $user->id,
        'model_type' => User::class,
    ]);

    (new WarmUserCacheJob($user->id))->handle(app(AuthorizationCacheManager::class));

    expect($this->repo->getUserPermissions($user->id))->toContain('web|posts.publish');
});

test('WarmAllCacheJob with flush=true calls warmAll', function () {
    $manager = Mockery::mock(AuthorizationCacheManager::class);
    $manager->shouldReceive('warmAll')->once();

    (new WarmAllCacheJob(flush: true))->handle($manager);
});

test('WarmAllCacheJob with flush=false calls rewarmAll', function () {
    $manager = Mockery::mock(AuthorizationCacheManager::class);
    $manager->shouldReceive('rewarmAll')->once();

    (new WarmAllCacheJob(flush: false))->handle($manager);
});

test('permissions-redis:stats displays cache statistics', function () {
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);

    // Scan returns some keys, then finishes
    $connection->shouldReceive('command')
        ->with('scan', ['0', 'match', 'auth:*', 'count', 100])
        ->once()
        ->andReturn(['0', [
            'auth:user:1:permissions',
            'auth:user:1:roles',
            'auth:user:2:permissions',
            'auth:user:2:roles',
            'auth:role:1:permissions',
            'auth:role:1:users',
        ]]);

    Redis::shouldReceive('connection')->with('default')->andReturn($connection);

    $this->artisan('permissions-redis:stats')
        ->assertSuccessful();
});
