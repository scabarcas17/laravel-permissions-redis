<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Closure;

class FakeTenantResolver
{
    public static int|string|null $current = null;

    public function __invoke(): int|string|null
    {
        return self::$current;
    }

    public static function closure(): Closure
    {
        return static fn (): int|string|null => self::$current;
    }
}
