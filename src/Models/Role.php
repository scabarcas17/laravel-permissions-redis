<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;
use Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $guard_name
 */
class Role extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'guard_name'];

    /** @var array<int, float> */
    private static array $rewarmAttempts = [];

    public static function findOrCreate(string $name, string $guardName = 'web'): static
    {
        if (str_contains($name, '|')) {
            throw new InvalidArgumentException("Role name cannot contain the '|' character.");
        }

        /** @var static $role */
        $role = static::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => $guardName],
        );

        return $role;
    }

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('permissions-redis.tables.roles', 'roles');

        return $table;
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        /** @var string $table */
        $table = config('permissions-redis.tables.role_has_permissions', 'role_has_permissions');

        return $this->belongsToMany(
            related: Permission::class,
            table: $table,
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }

    /** @return BelongsToMany<Model, $this> */
    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

        /** @var string $table */
        $table = config('permissions-redis.tables.model_has_roles', 'model_has_roles');

        return $this->belongsToMany(
            related: $userModel,
            table: $table,
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'model_id',
        );
    }

    /** @param array<string|int|BackedEnum> $permissions */
    public function syncPermissions(array $permissions): static
    {
        $this->permissions()->sync($this->resolvePermissionIds($permissions));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum ...$permissions */
    public function givePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->syncWithoutDetaching($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum ...$permissions */
    public function revokePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->detach($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    /**
     * Check whether this role grants the given permission. Uses the Redis-cached
     * role permission set (SISMEMBER) rather than a DB query.
     */
    public function hasPermission(string|BackedEnum $permission, ?string $guard = null): bool
    {
        $name = $permission instanceof BackedEnum ? (string) $permission->value : $permission;
        $guardName = $guard ?? $this->guard_name;
        $encoded = "{$guardName}|{$name}";

        /** @var PermissionRepositoryInterface $repository */
        $repository = app(PermissionRepositoryInterface::class);

        if ($repository->roleHasPermission($this->id, $encoded)) {
            return true;
        }

        // A miss can also mean the role's Redis key expired (TTL). Rewarm and
        // re-check before answering false, throttled with the same cooldown as
        // the resolver so genuinely-absent permissions cannot storm the DB.
        $cooldown = $this->rewarmCooldownSeconds();
        $lastAttempt = self::$rewarmAttempts[$this->id] ?? null;

        if ($cooldown > 0.0 && $lastAttempt !== null && (microtime(true) - $lastAttempt) < $cooldown) {
            return false;
        }

        self::$rewarmAttempts[$this->id] = microtime(true);

        /** @var AuthorizationCacheManager $cacheManager */
        $cacheManager = app(AuthorizationCacheManager::class);
        $cacheManager->warmRole($this->id);

        return $repository->roleHasPermission($this->id, $encoded);
    }

    /**
     * @internal Used by Octane reset and testing utilities.
     */
    public static function flushRewarmAttempts(): void
    {
        self::$rewarmAttempts = [];
    }

    protected static function booted(): void
    {
        static::saved(function (Role $role): void {
            if ($role->wasChanged('name')) {
                HasRedisPermissions::flushRoleIdNameCache();
            }
        });

        // Captured in `deleting` because the FK cascade wipes the pivot rows
        // before `deleted` fires, and the Redis role:users index may have
        // expired by then.
        static::deleting(function (Role $role): void {
            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);
            $role->setAttribute('_affected_user_ids', $cacheManager->getRoleUserIdsFromDb($role->id));
        });

        static::deleted(function (Role $role): void {
            HasRedisPermissions::flushRoleIdNameCache();

            /** @var array<int|string> $affectedUserIds */
            $affectedUserIds = $role->getAttribute('_affected_user_ids') ?? [];

            event(new RoleDeleted($role->id, $affectedUserIds));
        });
    }

    private function rewarmCooldownSeconds(): float
    {
        /** @var float|int $cooldown */
        $cooldown = config('permissions-redis.resolver_warm_cooldown', 1.0);

        return (float) $cooldown;
    }

    /**
     * @param array<mixed> $permissions
     *
     * @return array<int>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $intIds = [];
        $names = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof BackedEnum) {
                $names[] = (string) $permission->value;
            } elseif (is_string($permission)) {
                $names[] = $permission;
            } else {
                $intIds[] = is_numeric($permission) ? (int) $permission : 0;
            }
        }

        if ($names !== []) {
            $resolved = Permission::query()
                ->where('guard_name', $this->guard_name)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            $intIds = array_merge($intIds, $resolved);
        }

        return $intIds;
    }
}
