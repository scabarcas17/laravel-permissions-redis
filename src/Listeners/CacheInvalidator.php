<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Concerns\LogsMessages;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsAssigned;
use Scabarcas\LaravelPermissionsRedis\Events\PermissionsSynced;
use Scabarcas\LaravelPermissionsRedis\Events\RoleDeleted;
use Scabarcas\LaravelPermissionsRedis\Events\RolesAssigned;
use Scabarcas\LaravelPermissionsRedis\Events\UserDeleted;

class CacheInvalidator
{
    use LogsMessages;

    public function __construct(
        private readonly AuthorizationCacheManager $cacheManager,
        private readonly PermissionRepositoryInterface $repository,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PermissionsSynced::class, [self::class, 'handlePermissionsSynced']);
        $events->listen(PermissionsAssigned::class, [self::class, 'handlePermissionsAssigned']);
        $events->listen(RolesAssigned::class, [self::class, 'handleRolesAssigned']);
        $events->listen(RoleDeleted::class, [self::class, 'handleRoleDeleted']);
        $events->listen(UserDeleted::class, [self::class, 'handleUserDeleted']);
    }

    public function handlePermissionsSynced(PermissionsSynced $event): void
    {
        $key = $event->role->getKey();

        if (!is_int($key) && !is_string($key)) {
            return;
        }

        $roleId = is_int($key) ? $key : (int) $key;

        $this->log("Cache invalidation: role {$roleId} permissions synced.");

        $this->cacheManager->warmRole($roleId);

        $userIds = $this->repository->getRoleUserIds($roleId);

        foreach ($userIds as $userId) {
            $this->cacheManager->warmUser($userId);
        }

        $this->log("Cache invalidation complete: role {$roleId}, affected users: " . count($userIds));
    }

    public function handlePermissionsAssigned(PermissionsAssigned $event): void
    {
        /** @var int|string $userId */
        $userId = $event->user->getKey();

        $this->log("Cache invalidation: user {$userId} permissions assigned.");

        $this->cacheManager->warmUser($userId);
    }

    public function handleRolesAssigned(RolesAssigned $event): void
    {
        /** @var int|string $userId */
        $userId = $event->user->getKey();

        $this->log("Cache invalidation: user {$userId} roles assigned.");

        $this->cacheManager->warmUser($userId);

        $this->rewarmUserRoleIndexes($userId);
    }

    public function handleRoleDeleted(RoleDeleted $event): void
    {
        $roleId = $event->roleId;

        $this->log("Cache invalidation: role {$roleId} deleted.");

        $userIds = $this->repository->getRoleUserIds($roleId);

        $this->repository->deleteRoleCache($roleId);

        foreach ($userIds as $userId) {
            $this->cacheManager->warmUser($userId);
        }

        $this->log("Cache invalidation complete: role {$roleId} deleted, affected users: " . count($userIds));
    }

    public function handleUserDeleted(UserDeleted $event): void
    {
        $userId = $event->userId;

        $this->log("Cache invalidation: user {$userId} deleted.");

        $this->repository->deleteUserCache($userId);
    }

    private function rewarmUserRoleIndexes(int|string $userId): void
    {
        $userModels = $this->configuredUserModels();

        /** @var string $table */
        $table = config('permissions-redis.tables.model_has_roles', 'model_has_roles');

        $roleIds = DB::table($table)
            ->where('model_id', $userId)
            ->whereIn('model_type', $userModels)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            /** @var int $roleId */
            $this->cacheManager->warmRole($roleId);
        }
    }

    /**
     * @return array<string>
     */
    private function configuredUserModels(): array
    {
        $model = config('permissions-redis.user_model', 'App\\Models\\User');

        if (is_array($model)) {
            /** @var array<string> $model */
            return array_values($model);
        }

        /** @var string $model */
        return [$model];
    }
}
