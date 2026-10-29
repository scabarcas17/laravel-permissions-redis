<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Concerns;

/**
 * Routes the package's jobs through the `queue.connection` / `queue.name`
 * config entries. Called from the job constructor, so a later
 * onConnection() / onQueue() (the `--queue=connection` option) still wins.
 */
trait UsesConfiguredQueue
{
    private function useConfiguredQueue(): void
    {
        $connection = config('permissions-redis.queue.connection');
        $queue = config('permissions-redis.queue.name');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }
}
