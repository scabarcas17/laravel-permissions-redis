<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

enum TestRoleEnum: string
{
    case Admin = 'admin';
    case Editor = 'editor';
}
