<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Traits\DispatchesPermissionEvents;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 */
class DispatchingUser extends Model
{
    use DispatchesPermissionEvents;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = ['id'];
}
