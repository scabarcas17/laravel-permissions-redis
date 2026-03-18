<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class WarmCacheCommand extends Command
{
    protected $signature = 'permissions-redis:warm';

    protected $description = 'Warm the full authorization cache from database into Redis';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        $this->info('Warming authorization cache...');

        $start = microtime(true);

        $cacheManager->warmAll();

        $elapsed = round((microtime(true) - $start) * 1000);

        $this->info("Authorization cache warmed successfully in {$elapsed}ms.");

        return self::SUCCESS;
    }
}
