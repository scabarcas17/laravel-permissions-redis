<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Cache\ScansRedisByPrefix;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Throwable;

class CacheStatsCommand extends Command
{
    use ScansRedisByPrefix;

    protected $signature = 'permissions-redis:stats';

    protected $description = 'Display authorization cache statistics';

    /**
     * @throws Throwable
     */
    public function handle(PermissionRepositoryInterface $repository): int
    {
        /** @var string $connectionName */
        $connectionName = config('permissions-redis.redis_connection', 'default');
        $connection = Redis::connection($connectionName);

        /** @var string $prefix */
        $prefix = config('permissions-redis.prefix', 'auth:');

        $userIds = [];
        $roleIds = [];
        $totalKeys = 0;

        $this->scanByPattern($connection, $prefix . '*', function (array $keys) use ($prefix, &$userIds, &$roleIds, &$totalKeys): void {
            foreach ($keys as $key) {
                $totalKeys++;

                $relative = substr($key, strlen($prefix));

                if (preg_match('/^user:(.+):permissions$/', $relative, $matches)) {
                    $userIds[$matches[1]] = true;
                } elseif (preg_match('/^role:(.+):permissions$/', $relative, $matches)) {
                    $roleIds[$matches[1]] = true;
                }
            }
        });

        /** @var int $ttl */
        $ttl = config('permissions-redis.ttl', 86400);

        $this->table(['Metric', 'Value'], [
            ['Cached users', (string) count($userIds)],
            ['Cached roles', (string) count($roleIds)],
            ['Total keys', (string) $totalKeys],
            ['TTL (seconds)', (string) $ttl],
            ['Redis connection', $connectionName],
            ['Key prefix', $prefix],
        ]);

        return self::SUCCESS;
    }
}
