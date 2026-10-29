<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-deletable variant of the User fixture, sharing the users table.
 */
class SoftDeletingUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}
