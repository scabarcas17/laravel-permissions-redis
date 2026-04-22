<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Jobs\WarmUserCacheJob;

class WarmUserCacheCommand extends Command
{
    protected $signature = 'permissions-redis:warm-user
        {userId : The user ID to warm cache for}
        {--queue= : Dispatch as a queued job on the given connection (omit value for default connection)}';

    protected $description = 'Warm the authorization cache for a specific user';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        /** @var string $rawId */
        $rawId = $this->argument('userId');
        $userId = ctype_digit($rawId) ? (int) $rawId : $rawId;

        if ($this->optionProvided('queue')) {
            /** @var string|null $connection */
            $connection = $this->option('queue') ?: null;

            $job = new WarmUserCacheJob($userId);

            if ($connection !== null) {
                $job->onConnection($connection);
            }

            dispatch($job);

            $this->info("Queued authorization cache warm for user {$userId}.");

            return self::SUCCESS;
        }

        $this->info("Warming authorization cache for user {$userId}...");

        $cacheManager->warmUser($userId);

        $this->info("Authorization cache warmed for user {$userId}.");

        return self::SUCCESS;
    }

    private function optionProvided(string $name): bool
    {
        return $this->input->hasParameterOption(["--{$name}"]);
    }
}
