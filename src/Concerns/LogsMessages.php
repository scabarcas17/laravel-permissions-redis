<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Concerns;

use Illuminate\Support\Facades\Log;

trait LogsMessages
{
    private function log(string $message, string $level = 'info'): void
    {
        /** @var string|null $channel */
        $channel = config('permissions-redis.log_channel');

        if ($channel !== null) {
            Log::channel($channel)->{$level}("[permissions-redis] {$message}");
        } else {
            Log::{$level}("[permissions-redis] {$message}");
        }
    }
}
