<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\ResolvesUserModels;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $guard_name
 */
class Role extends Model
{
    use ResolvesUserModels;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'guard_name'];

    /** @var array<int, float> */
    private static array $rewarmAttempts = [];

    /**
     * Process-wide id => name memo behind HasRedisPermissions::hasRole() for
     * integer IDs. It lives on this class rather than on the trait because a
     * static property declared in a trait is copied into every class using
     * it, so a flush issued from the outside could never reach them all.
     *
     * @var array<int, string|null>
     */
    private static array $idNameCache = [];

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

    /**
     * Role name for an ID, memoised for the lifetime of the process.
     *
     * @internal Used by HasRedisPermissions::hasRole(); flushed on rename,
     *           delete and at Octane / queue worker boundaries.
     */
    public static function nameById(int $roleId): ?string
    {
        if (!array_key_exists($roleId, self::$idNameCache)) {
            $name = static::query()->where('id', $roleId)->value('name');

            self::$idNameCache[$roleId] = is_string($name) ? $name : null;
        }

        return self::$idNameCache[$roleId];
    }

    /**
     * @internal Used by Octane / queue resets and testing utilities.
     */
    public static function flushIdNameCache(): void
    {
        self::$idNameCache = [];
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

    /**
     * Users holding this role. With several configured user models the
     * relation targets the first one; the model_type filter keeps rows of
     * the other models (which may share IDs) out of the result.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function users(): BelongsToMany
    {
        $userModel = $this->primaryUserModel();

        /** @var string $table */
        $table = config('permissions-redis.tables.model_has_roles', 'model_has_roles');

        return $this->belongsToMany(
            related: $userModel,
            table: $table,
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'model_id',
        )
            ->wherePivot('model_type', $userModel)
            ->withPivotValue('model_type', $userModel);
    }

    /** @param string|int|array<string|int|Permission>|BackedEnum|Permission ...$permissions */
    public function syncPermissions(mixed ...$permissions): static
    {
        $permissions = is_array($permissions[0] ?? null) ? $permissions[0] : $permissions;

        $this->permissions()->sync($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum|Permission ...$permissions */
    public function givePermissionTo(mixed ...$permissions): static
    {
        $this->permissions()->syncWithoutDetaching($this->resolvePermissionIds(collect($permissions)->flatten()->all()));

        event(new PermissionsSynced($this));

        return $this;
    }

    /** @param string|int|BackedEnum|Permission ...$permissions */
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
        // A rename (or guard change) alters the "guard|name" entry cached in
        // Redis for every user holding the role, so those users are rewarmed
        // and the id => name memo dropped. Hooked on `updated` because
        // wasChanged() is empty on inserts.
        static::updated(function (Role $role): void {
            if (!$role->wasChanged('name') && !$role->wasChanged('guard_name')) {
                return;
            }

            self::flushIdNameCache();

            /** @var AuthorizationCacheManager $cacheManager */
            $cacheManager = app(AuthorizationCacheManager::class);

            /** @var PermissionResolverInterface $resolver */
            $resolver = app(PermissionResolverInterface::class);

            foreach ($cacheManager->getRoleUserIdsFromDb($role->id) as $userId) {
                $cacheManager->warmUser($userId);
                $resolver->flushUser($userId);
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
            self::flushIdNameCache();

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
     * Resolve names, IDs, enums and Permission instances to IDs. Integer IDs
     * (and instances) are validated against this role's guard and silently
     * dropped when they belong to another one, as the user trait does.
     *
     * @param array<mixed> $permissions
     *
     * @return array<int>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $rawIds = [];
        $names = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof Permission) {
                $rawIds[] = $permission->id;
            } elseif ($permission instanceof BackedEnum) {
                $names[] = (string) $permission->value;
            } elseif (is_string($permission)) {
                $names[] = $permission;
            } elseif (is_object($permission)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported permission identifier of type %s: pass a name, an ID, a BackedEnum or a Permission instance.',
                    $permission::class,
                ));
            } else {
                $rawIds[] = is_numeric($permission) ? (int) $permission : 0;
            }
        }

        $validated = [];

        if ($rawIds !== []) {
            $validated = Permission::query()
                ->where('guard_name', $this->guard_name)
                ->whereIn('id', $rawIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();
        }

        if ($names !== []) {
            $resolved = Permission::query()
                ->where('guard_name', $this->guard_name)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            $validated = array_merge($validated, $resolved);
        }

        return $validated;
    }
}
