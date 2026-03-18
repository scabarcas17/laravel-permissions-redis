<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class WarmUserCacheCommand extends Command
{
    protected $signature = 'permissions-redis:warm-user {userId : The user ID to warm cache for}';

    protected $description = 'Warm the authorization cache for a specific user';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        $userId = (int) $this->argument('userId');

        $this->info("Warming authorization cache for user {$userId}...");

        $cacheManager->warmUser($userId);

        $this->info("Authorization cache warmed for user {$userId}.");

        return self::SUCCESS;
    }
}
