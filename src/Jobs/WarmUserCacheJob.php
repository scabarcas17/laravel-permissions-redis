<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class WarmUserCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int|string $userId,
    ) {
    }

    public function handle(AuthorizationCacheManager $cacheManager): void
    {
        $cacheManager->warmUser($this->userId);
    }
}
