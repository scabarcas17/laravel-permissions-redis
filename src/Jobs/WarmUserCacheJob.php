<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\UsesConfiguredQueue;

class WarmUserCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesConfiguredQueue;

    public function __construct(
        public readonly int|string $userId,
    ) {
        $this->useConfiguredQueue();
    }

    public function handle(AuthorizationCacheManager $cacheManager): void
    {
        $cacheManager->warmUser($this->userId);
    }
}
