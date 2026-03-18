<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Cache;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;

class AuthorizationCacheManager
{
    public function __construct(
        private readonly PermissionRepositoryInterface $repository,
    ) {
    }

    /**
     * Warm the entire authorization cache from database.
     */
    public function warmAll(): void
    {
        $this->repository->flushAll();

        $this->warmAllRoles();
        $this->warmAllUsers();

        $this->log('Full authorization cache warm completed.');
    }

    /**
     * Recompute and warm a single user's permissions and roles cache.
     */
    public function warmUser(int $userId): void
    {
        $permissions = $this->computeUserPermissions($userId);
        $roles = $this->getUserRoleNames($userId);

        $this->repository->setUserPermissions($userId, $permissions);
        $this->repository->setUserRoles($userId, $roles);
    }

    /**
     * Recompute and warm a single role's permissions cache and its user reverse index.
     */
    public function warmRole(int $roleId): void
    {
        $permissions = $this->getRolePermissionNames($roleId);
        $this->repository->setRolePermissions($roleId, $permissions);

        $userIds = $this->getRoleUserIdsFromDb($roleId);
        $this->repository->setRoleUsers($roleId, $userIds);
    }

    /**
     * Remove all cache entries for a user.
     */
    public function evictUser(int $userId): void
    {
        $this->repository->deleteUserCache($userId);
    }

    /**
     * Remove all cache entries for a role.
     */
    public function evictRole(int $roleId): void
    {
        $this->repository->deleteRoleCache($roleId);
    }

    /**
     * Compute the full resolved permission set for a user (role-inherited + direct).
     *
     * @return array<string>
     */
    private function computeUserPermissions(int $userId): array
    {
        $rolePermissions = $this->getUserRolePermissionNames($userId);
        $directPermissions = $this->getUserDirectPermissionNames($userId);

        return array_values(array_unique(array_merge($rolePermissions, $directPermissions)));
    }

    /**
     * @return array<string>
     */
    private function getUserRolePermissionNames(int $userId): array
    {
        /** @var array<string> $rows */
        $rows = DB::table($this->table('model_has_roles'))
            ->join(
                $this->table('role_has_permissions'),
                $this->table('role_has_permissions') . '.role_id',
                '=',
                $this->table('model_has_roles') . '.role_id'
            )
            ->join(
                $this->table('permissions'),
                $this->table('permissions') . '.id',
                '=',
                $this->table('role_has_permissions') . '.permission_id'
            )
            ->where($this->table('model_has_roles') . '.model_id', $userId)
            ->where($this->table('model_has_roles') . '.model_type', $this->userModelType())
            ->pluck($this->table('permissions') . '.name')
            ->all();

        return $rows;
    }

    /**
     * @return array<string>
     */
    private function getUserDirectPermissionNames(int $userId): array
    {
        /** @var array<string> $rows */
        $rows = DB::table($this->table('model_has_permissions'))
            ->join(
                $this->table('permissions'),
                $this->table('permissions') . '.id',
                '=',
                $this->table('model_has_permissions') . '.permission_id'
            )
            ->where($this->table('model_has_permissions') . '.model_id', $userId)
            ->where($this->table('model_has_permissions') . '.model_type', $this->userModelType())
            ->pluck($this->table('permissions') . '.name')
            ->all();

        return $rows;
    }

    /**
     * @return array<string>
     */
    private function getUserRoleNames(int $userId): array
    {
        /** @var array<string> $rows */
        $rows = DB::table($this->table('model_has_roles'))
            ->join(
                $this->table('roles'),
                $this->table('roles') . '.id',
                '=',
                $this->table('model_has_roles') . '.role_id'
            )
            ->where($this->table('model_has_roles') . '.model_id', $userId)
            ->where($this->table('model_has_roles') . '.model_type', $this->userModelType())
            ->pluck($this->table('roles') . '.name')
            ->all();

        return $rows;
    }

    /**
     * @return array<string>
     */
    private function getRolePermissionNames(int $roleId): array
    {
        /** @var array<string> $rows */
        $rows = DB::table($this->table('role_has_permissions'))
            ->join(
                $this->table('permissions'),
                $this->table('permissions') . '.id',
                '=',
                $this->table('role_has_permissions') . '.permission_id'
            )
            ->where($this->table('role_has_permissions') . '.role_id', $roleId)
            ->pluck($this->table('permissions') . '.name')
            ->all();

        return $rows;
    }

    /**
     * @return array<int>
     */
    private function getRoleUserIdsFromDb(int $roleId): array
    {
        /** @var array<int> $rows */
        $rows = DB::table($this->table('model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', $this->userModelType())
            ->pluck('model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $rows;
    }

    private function warmAllRoles(): void
    {
        /** @var Collection<int, int> $roles */
        $roles = DB::table($this->table('roles'))->pluck('id');

        foreach ($roles as $roleId) {
            $this->warmRole((int) $roleId);
        }
    }

    private function warmAllUsers(): void
    {
        /** @var string $userModel */
        $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

        $table = (new $userModel())->getTable();

        /** @var Collection<int, int> $userIds */
        $userIds = DB::table($table)->pluck('id');

        foreach ($userIds as $userId) {
            $this->warmUser((int) $userId);
        }
    }

    private function userModelType(): string
    {
        /** @var string $model */
        $model = config('permissions-redis.user_model', 'App\\Models\\User');

        return $model;
    }

    private function table(string $key): string
    {
        /** @var string $table */
        $table = config("permissions-redis.tables.{$key}", $key);

        return $table;
    }

    private function log(string $message): void
    {
        /** @var string|null $channel */
        $channel = config('permissions-redis.log_channel');

        if ($channel !== null) {
            Log::channel($channel)->info($message);
        } else {
            Log::info("[permissions-redis] {$message}");
        }
    }
}
