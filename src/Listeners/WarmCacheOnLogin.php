<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

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

        /** @var int $userId */
        $userId = $user->getKey();

        $this->cacheManager->warmUser($userId);
    }
}
