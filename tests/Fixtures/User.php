<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 */
class User extends Model
{
    use HasRedisPermissions;

    public $timestamps = false;

    protected $guarded = ['id'];
}
