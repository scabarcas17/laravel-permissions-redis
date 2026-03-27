<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

enum TestPermissionEnum: string
{
    case Create = 'users.create';
    case Edit = 'users.edit';
}
