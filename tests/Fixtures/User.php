<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Sebastian\LaravelPermissionsRedis\Traits\HasRedisPermissions;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 */
class User extends Model
{
    use HasRedisPermissions;

    protected $guarded = ['id'];

    public $timestamps = false;
}
