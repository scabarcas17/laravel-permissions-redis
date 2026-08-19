<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

class FlushCacheCommand extends Command
{
    protected $signature = 'permissions-redis:flush
                            {--force : Skip the confirmation prompt (for scripts and CI)}';

    protected $description = 'Flush all authorization cache entries from Redis';

    public function handle(PermissionRepositoryInterface $repository): int
    {
        if (!$this->option('force') && !$this->confirm('This will remove all authorization cache entries. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $repository->flushAll();

        $this->info('Authorization cache flushed.');

        return self::SUCCESS;
    }
}
