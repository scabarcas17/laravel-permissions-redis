<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class WarmCacheOnLogin
{
    public function __construct(
        private readonly AuthorizationCacheManager $cacheManager,
    ) {
    }

    public function handle(Login $event): void
    {
        /** @var Model $user */
        $user = $event->user;

        $this->cacheManager->warmUser((int) $user->getKey());
    }
}
