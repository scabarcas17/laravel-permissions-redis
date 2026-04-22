<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Jobs\WarmAllCacheJob;

class WarmCacheCommand extends Command
{
    protected $signature = 'permissions-redis:warm
        {--no-flush : Rewarm without flushing existing cache first}
        {--queue= : Dispatch as a queued job on the given connection (omit value for default connection)}';

    protected $description = 'Warm the full authorization cache from database into Redis';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        $noFlush = (bool) $this->option('no-flush');

        if ($this->optionProvided('queue')) {
            /** @var string|null $connection */
            $connection = $this->option('queue') ?: null;

            $job = new WarmAllCacheJob(flush: !$noFlush);

            if ($connection !== null) {
                $job->onConnection($connection);
            }

            dispatch($job);

            $this->info($noFlush
                ? 'Queued authorization cache rewarm (no flush).'
                : 'Queued authorization cache warm.');

            return self::SUCCESS;
        }

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

    private function optionProvided(string $name): bool
    {
        return $this->input->hasParameterOption(["--{$name}"]);
    }
}
