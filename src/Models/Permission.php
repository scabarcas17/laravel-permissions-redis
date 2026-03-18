<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

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

    public static function findOrCreate(string $name, string $guardName = 'web', ?string $group = null): static
    {
        /** @var static $permission */
        $permission = static::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => $guardName],
            ['group' => $group],
        );

        return $permission;
    }

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('permissions-redis.tables.permissions', 'permissions');

        return $table;
    }

    protected static function booted(): void
    {
        static::updated(function (Permission $permission): void {
            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);
            $cacheManager->warmAll();
        });

        static::deleted(function (Permission $permission): void {
            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);
            $cacheManager->warmAll();
        });
    }
}
