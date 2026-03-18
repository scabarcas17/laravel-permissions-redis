<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string|null $group
 * @property string      $guard_name
 */
class Permission extends Model
{
    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('permissions-redis.tables.permissions', 'permissions');
    }
}
