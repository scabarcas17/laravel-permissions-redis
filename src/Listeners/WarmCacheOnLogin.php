<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Listeners;

use Illuminate\Auth\Events\Login;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\ResolvesUserModels;

class WarmCacheOnLogin
{
    use ResolvesUserModels;

    public function __construct(
        private readonly AuthorizationCacheManager $cacheManager,
    ) {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        // Only the models this package authorizes get a cache entry. Other
        // Authenticatables (an admin model without the trait, a GenericUser
        // from a custom provider) may share IDs with them and would write
        // under the wrong key, and non-Eloquent users have no getKey().
        if (!$this->isConfiguredUserModel($user)) {
            return;
        }

        $userId = $user->getAuthIdentifier();

        if (!is_int($userId) && !is_string($userId)) {
            return;
        }

        $this->cacheManager->warmUser($userId);
    }
}
