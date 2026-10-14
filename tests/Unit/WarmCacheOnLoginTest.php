<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\AdminUser;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

test('warms user cache on login event', function () {
    $user = new User();
    $user->id = 42;

    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldReceive('warmUser')->with(42)->once();

    $listener = new WarmCacheOnLogin($cacheManager);
    $listener->handle(new Login('web', $user, false));
});

test('skips authenticatables that are not an Eloquent user model', function () {
    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldNotReceive('warmUser');

    $listener = new WarmCacheOnLogin($cacheManager);
    $listener->handle(new Login('web', new GenericUser(['id' => 7]), false));
});

test('skips models that are not configured as user models', function () {
    config()->set('permissions-redis.user_model', User::class);

    $admin = new AdminUser();
    $admin->id = 7;

    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldNotReceive('warmUser');

    (new WarmCacheOnLogin($cacheManager))->handle(new Login('admin', $admin, false));
});

test('warms every model listed in a multi-model configuration', function () {
    config()->set('permissions-redis.user_model', [User::class, AdminUser::class]);

    $admin = new AdminUser();
    $admin->id = 7;

    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldReceive('warmUser')->with(7)->once();

    (new WarmCacheOnLogin($cacheManager))->handle(new Login('admin', $admin, false));
});
