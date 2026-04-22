<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Scabarcas\LaravelPermissionsRedis\PermissionsRedisServiceProvider;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

test('binds PermissionRepositoryInterface to RedisPermissionRepository', function () {
    // TestCase binds the InMemoryPermissionRepository by default so Eloquent
    // model hooks do not hit Redis. Re-register the service provider in
    // isolation to verify the production default binding.
    $this->app->forgetInstance(PermissionRepositoryInterface::class);
    unset($this->app[PermissionRepositoryInterface::class]);

    (new PermissionsRedisServiceProvider($this->app))->register();

    $instance = app(PermissionRepositoryInterface::class);

    expect($instance)->toBeInstanceOf(RedisPermissionRepository::class);
});

test('binds PermissionResolverInterface to PermissionResolver', function () {
    $instance = app(PermissionResolverInterface::class);

    expect($instance)->toBeInstanceOf(PermissionResolver::class);
});

test('registers AuthorizationCacheManager as singleton', function () {
    $a = app(AuthorizationCacheManager::class);
    $b = app(AuthorizationCacheManager::class);

    expect($a)->toBe($b);
});

test('registers PermissionResolver as singleton via interface', function () {
    $a = app(PermissionResolverInterface::class);
    $b = app(PermissionResolverInterface::class);

    expect($a)->toBe($b);
});

test('merges package config', function () {
    expect(config('permissions-redis.prefix'))->toBe('auth:')
        ->and(config('permissions-redis.ttl'))->toBe(86400)
        ->and(config('permissions-redis.tables.permissions'))->toBe('permissions');
});

test('registers middleware aliases when config enabled', function () {
    config()->set('permissions-redis.register_middleware', true);

    (new PermissionsRedisServiceProvider($this->app))->boot();

    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $middleware = $router->getMiddleware();

    expect($middleware)->toHaveKey('permission')
        ->and($middleware)->toHaveKey('role')
        ->and($middleware)->toHaveKey('role_or_permission');
});

test('registers Gate::before callback when config enabled', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    config()->set('permissions-redis.register_gate', true);
    config()->set('permissions-redis.user_model', User::class);

    (new PermissionsRedisServiceProvider($this->app))->boot();

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    app(AuthorizationCacheManager::class)->warmUser($user->id);

    expect(Gate::forUser($user)->allows('users.create'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('nonexistent'))->toBeFalse();
});

test('Gate::before skips non-matching user models', function () {
    config()->set('permissions-redis.register_gate', true);
    config()->set('permissions-redis.user_model', 'App\\Models\\User');

    (new PermissionsRedisServiceProvider($this->app))->boot();

    // Our fixture User is not App\Models\User, so the gate callback should skip it
    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    expect(Gate::forUser($user)->allows('anything'))->toBeFalse();
});

test('Gate::before supports multiple user models configured as array', function () {
    $repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $repo);
    $this->app->singleton(AuthorizationCacheManager::class, fn () => new AuthorizationCacheManager($repo));

    config()->set('permissions-redis.register_gate', true);
    config()->set('permissions-redis.user_model', [User::class, 'App\\Models\\Admin']);

    (new PermissionsRedisServiceProvider($this->app))->boot();

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'users.create', 'guard_name' => 'web']);
    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    app(AuthorizationCacheManager::class)->warmUser($user->id);

    expect(Gate::forUser($user)->allows('users.create'))->toBeTrue();
});

test('registers warm on login listener when config enabled', function () {
    Event::fake();

    config()->set('permissions-redis.warm_on_login', true);

    (new PermissionsRedisServiceProvider($this->app))->boot();

    Event::assertListening(Login::class, WarmCacheOnLogin::class);
});

test('registers blade directives when config enabled', function () {
    config()->set('permissions-redis.register_blade_directives', true);

    (new PermissionsRedisServiceProvider($this->app))->boot();

    // Blade::if registers conditional directives that compile to if statements
    // Verify by compiling a blade template containing one of our directives
    $compiled = Blade::compileString('@role("admin") @endrole');

    expect($compiled)->toContain('Blade::check');
});
