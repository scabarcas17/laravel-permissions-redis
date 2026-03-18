<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Sebastian\LaravelPermissionsRedis\Tests\Fixtures\User;

test('warms user cache on login event', function () {
    $user = new User();
    $user->id = 42;

    $cacheManager = Mockery::mock(AuthorizationCacheManager::class);
    $cacheManager->shouldReceive('warmUser')->with(42)->once();

    $listener = new WarmCacheOnLogin($cacheManager);
    $listener->handle(new Login('web', $user, false));
});
