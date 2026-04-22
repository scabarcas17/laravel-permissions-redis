<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Exceptions;

use RuntimeException;

class TransactionFailedException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Redis MULTI/EXEC transaction aborted for key '{$key}'.");
    }

    public static function forBatch(int $setCount): self
    {
        return new self("Redis MULTI/EXEC batch transaction aborted ({$setCount} sets affected).");
    }
}
