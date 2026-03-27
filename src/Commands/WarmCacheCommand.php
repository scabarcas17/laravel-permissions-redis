<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class WarmCacheCommand extends Command
{
    protected $signature = 'permissions-redis:warm {--no-flush : Rewarm without flushing existing cache first}';

    protected $description = 'Warm the full authorization cache from database into Redis';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        $noFlush = $this->option('no-flush');

        $this->info($noFlush ? 'Rewarming authorization cache (no flush)...' : 'Warming authorization cache...');

        $start = microtime(true);

        if ($noFlush) {
            $cacheManager->rewarmAll();
        } else {
            $cacheManager->warmAll();
        }

        $elapsed = round((microtime(true) - $start) * 1000);

        $this->info("Authorization cache warmed successfully in {$elapsed}ms.");

        return self::SUCCESS;
    }
}
