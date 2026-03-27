<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
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
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'group', 'guard_name'];

    public static function findOrCreate(string $name, string $guardName = 'web', ?string $group = null): static
    {
        if (str_contains($name, '|')) {
            throw new InvalidArgumentException("Permission name cannot contain the '|' character.");
        }

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
            $cacheManager->warmPermissionAffectedUsers($permission->id);
        });

        static::deleting(function (Permission $permission): void {
            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);
            $permission->setAttribute('_affected_user_ids', $cacheManager->getUserIdsAffectedByPermission($permission->id));
        });

        static::deleted(function (Permission $permission): void {
            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);

            /** @var array<int> $affectedUserIds */
            $affectedUserIds = $permission->getAttribute('_affected_user_ids') ?? [];

            foreach ($affectedUserIds as $userId) {
                $cacheManager->warmUser($userId);
            }
        });
    }
}
