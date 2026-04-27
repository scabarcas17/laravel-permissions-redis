<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Scabarcas\LaravelPermissionsRedis\Concerns\LogsMessages;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use stdClass;

class AuthorizationCacheManager
{
    use LogsMessages;

    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
    ) {
    }

    /** Flushes before warming. Use rewarmAll() to avoid the downtime window. */
    public function warmAll(): void
    {
        $this->repository->flushAll();

        $this->warmAllRoles();
        $this->warmAllUsers();
        $this->warmPermissionGroups();

        $this->log('Full authorization cache warm completed (flushed first).');
    }

    /** No flush: stale keys from removed entities persist until their TTL expires. */
    public function rewarmAll(): void
    {
        $this->warmAllRoles();
        $this->warmAllUsers();
        $this->warmPermissionGroups();

        $this->log('Cache rewarm completed (no flush).');
    }

    public function warmPermissionGroups(): void
    {
        $batch = [];

        DB::table($this->table('permissions'))
            ->select('guard_name', 'name', 'group')
            ->orderBy('id')
            ->chunk(500, function (Collection $permissions) use (&$batch): void {
                foreach ($permissions as $permission) {
                    $guard = is_string($permission->guard_name) ? $permission->guard_name : '';
                    $name = is_string($permission->name) ? $permission->name : '';
                    $group = is_string($permission->group) && $permission->group !== '' ? $permission->group : null;

                    $batch["{$guard}|{$name}"] = $group;
                }
            });

        $this->repository->replacePermissionGroups($batch);
    }

    public function warmUser(int|string $userId): void
    {
        $permissions = $this->computeUserPermissions($userId);
        $roles = $this->getUserRoleNames($userId);

        $this->repository->replaceSetBatch([
            "user:{$userId}:permissions" => $permissions,
            "user:{$userId}:roles"       => $roles,
        ]);
    }

    public function warmRole(int $roleId): void
    {
        $permissions = $this->getRolePermissionNames($roleId);
        $userIds = $this->getRoleUserIdsFromDb($roleId);

        $this->repository->replaceSetBatch([
            "role:{$roleId}:permissions" => $permissions,
            "role:{$roleId}:users"       => array_map('strval', $userIds),
        ]);
    }

    public function warmPermissionAffectedUsers(int $permissionId): void
    {
        $affectedUserIds = $this->getUserIdsAffectedByPermission($permissionId);

        foreach ($affectedUserIds as $userId) {
            $this->warmUser($userId);
        }

        $this->warmAffectedRolesByPermission($permissionId);

        $this->log('Warmed cache for ' . count($affectedUserIds) . " users affected by permission [{$permissionId}].");
    }

    public function evictUser(int|string $userId): void
    {
        $this->repository->deleteUserCache($userId);
    }

    public function evictRole(int $roleId): void
    {
        $this->repository->deleteRoleCache($roleId);
    }

    /** @return array<int|string> */
    public function getUserIdsAffectedByPermission(int $permissionId): array
    {
        $modelTypes = $this->userModelTypes();

        $directUserIds = DB::table($this->table('model_has_permissions'))
            ->where('permission_id', $permissionId)
            ->whereIn('model_type', $modelTypes)
            ->pluck('model_id');

        $roleIds = DB::table($this->table('role_has_permissions'))
            ->where('permission_id', $permissionId)
            ->pluck('role_id');

        $roleUserIds = $roleIds->isNotEmpty()
            ? DB::table($this->table('model_has_roles'))
                ->whereIn('role_id', $roleIds)
                ->whereIn('model_type', $modelTypes)
                ->pluck('model_id')
            : collect();

        /** @var array<int|string> */
        return $directUserIds->merge($roleUserIds)->unique()->values()->all();
    }

    /** @return array<string> */
    private function computeUserPermissions(int|string $userId): array
    {
        $rolePermissions = $this->getUserRolePermissionNames($userId);
        $directPermissions = $this->getUserDirectPermissionNames($userId);

        return array_values(array_unique(array_merge($rolePermissions, $directPermissions)));
    }

    /** @return array<string> */
    private function getUserRolePermissionNames(int|string $userId): array
    {
        $permissionsTable = $this->table('permissions');

        return $this->encodeRows(
            DB::table($this->table('model_has_roles'))
                ->join(
                    $this->table('role_has_permissions'),
                    $this->table('role_has_permissions') . '.role_id',
                    '=',
                    $this->table('model_has_roles') . '.role_id'
                )
                ->join(
                    $permissionsTable,
                    $permissionsTable . '.id',
                    '=',
                    $this->table('role_has_permissions') . '.permission_id'
                )
                ->where($this->table('model_has_roles') . '.model_id', $userId)
                ->whereIn($this->table('model_has_roles') . '.model_type', $this->userModelTypes())
                ->select($permissionsTable . '.guard_name', $permissionsTable . '.name')
                ->cursor()
        );
    }

    /** @return array<string> */
    private function getUserDirectPermissionNames(int|string $userId): array
    {
        $permissionsTable = $this->table('permissions');

        return $this->encodeRows(
            DB::table($this->table('model_has_permissions'))
                ->join(
                    $permissionsTable,
                    $permissionsTable . '.id',
                    '=',
                    $this->table('model_has_permissions') . '.permission_id'
                )
                ->where($this->table('model_has_permissions') . '.model_id', $userId)
                ->whereIn($this->table('model_has_permissions') . '.model_type', $this->userModelTypes())
                ->select($permissionsTable . '.guard_name', $permissionsTable . '.name')
                ->cursor()
        );
    }

    /** @return array<string> */
    private function getUserRoleNames(int|string $userId): array
    {
        $rolesTable = $this->table('roles');

        return $this->encodeRows(
            DB::table($this->table('model_has_roles'))
                ->join(
                    $rolesTable,
                    $rolesTable . '.id',
                    '=',
                    $this->table('model_has_roles') . '.role_id'
                )
                ->where($this->table('model_has_roles') . '.model_id', $userId)
                ->whereIn($this->table('model_has_roles') . '.model_type', $this->userModelTypes())
                ->select($rolesTable . '.guard_name', $rolesTable . '.name')
                ->cursor()
        );
    }

    /** @return array<string> */
    private function getRolePermissionNames(int $roleId): array
    {
        $permissionsTable = $this->table('permissions');

        return $this->encodeRows(
            DB::table($this->table('role_has_permissions'))
                ->join(
                    $permissionsTable,
                    $permissionsTable . '.id',
                    '=',
                    $this->table('role_has_permissions') . '.permission_id'
                )
                ->where($this->table('role_has_permissions') . '.role_id', $roleId)
                ->select($permissionsTable . '.guard_name', $permissionsTable . '.name')
                ->cursor()
        );
    }

    /**
     * @return array<int|string>
     */
    private function getRoleUserIdsFromDb(int $roleId): array
    {
        /** @var array<int|string> */
        return DB::table($this->table('model_has_roles'))
            ->where('role_id', $roleId)
            ->whereIn('model_type', $this->userModelTypes())
            ->pluck('model_id')
            ->all();
    }

    private function warmAllRoles(): void
    {
        DB::table($this->table('roles'))->orderBy('id')->chunk(200, function (Collection $roles): void {
            $batch = [];

            foreach ($roles as $role) {
                /** @var int $roleId */
                $roleId = $role->id;

                $permissions = $this->getRolePermissionNames($roleId);
                $userIds = $this->getRoleUserIdsFromDb($roleId);

                $batch["role:{$roleId}:permissions"] = $permissions;
                $batch["role:{$roleId}:users"] = array_map('strval', $userIds);
            }

            $this->repository->replaceSetBatch($batch);
        });
    }

    private function warmAllUsers(): void
    {
        $modelTypes = $this->userModelTypes();

        $userIds = DB::table($this->table('model_has_roles'))
            ->whereIn('model_type', $modelTypes)
            ->select('model_id')
            ->union(
                DB::table($this->table('model_has_permissions'))
                    ->whereIn('model_type', $modelTypes)
                    ->select('model_id')
            )
            ->distinct()
            ->pluck('model_id');

        $userIds->chunk(200)->each(function (Collection $chunk): void {
            $batch = [];

            foreach ($chunk as $userId) {
                /** @var int|string $userId */
                $permissions = $this->computeUserPermissions($userId);
                $roles = $this->getUserRoleNames($userId);

                $batch["user:{$userId}:permissions"] = $permissions;
                $batch["user:{$userId}:roles"] = $roles;
            }

            $this->repository->replaceSetBatch($batch);
        });
    }

    /**
     * @return array<string>
     */
    private function userModelTypes(): array
    {
        $model = config('permissions-redis.user_model', 'App\\Models\\User');

        if (is_array($model)) {
            /** @var array<string> $model */
            return array_values($model);
        }

        /** @var string $model */
        return [$model];
    }

    private function table(string $key): string
    {
        /** @var string $table */
        $table = config("permissions-redis.tables.{$key}", $key);

        return $table;
    }

    private function warmAffectedRolesByPermission(int $permissionId): void
    {
        $roleIds = DB::table($this->table('role_has_permissions'))
            ->where('permission_id', $permissionId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            /** @var int $roleId */
            $this->warmRole($roleId);
        }
    }

    /**
     * @param iterable<stdClass> $rows
     *
     * @return array<string>
     */
    private function encodeRows(iterable $rows): array
    {
        $encoded = [];

        foreach ($rows as $row) {
            $guard = is_string($row->guard_name) ? $row->guard_name : '';
            $name = is_string($row->name) ? $row->name : '';
            $encoded[] = $this->encodeValue($guard, $name);
        }

        return $encoded;
    }

    private function encodeValue(string $guard, string $name): string
    {
        if (str_contains($guard, '|') || str_contains($name, '|')) {
            throw new InvalidArgumentException('Guard/name cannot contain the pipe separator.');
        }

        return "{$guard}|{$name}";
    }
}
