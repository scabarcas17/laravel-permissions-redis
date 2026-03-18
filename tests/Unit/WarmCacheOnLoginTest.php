<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

test('warms user cache on login event', function () {
    $user = new User();
    $user->id = 42;

    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldReceive('warmUser')->with(42)->once();

    $listener = new WarmCacheOnLogin($cacheManager);
    $listener->handle(new Login('web', $user, false));
});
