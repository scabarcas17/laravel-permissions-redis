<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Events\PermissionsSynced;
use Sebastian\LaravelPermissionsRedis\Events\RoleDeleted;
use Sebastian\LaravelPermissionsRedis\Events\RolesAssigned;
use Sebastian\LaravelPermissionsRedis\Events\UserDeleted;

class CacheInvalidator
{
    public function __construct(
        private readonly AuthorizationCacheManager $cacheManager,
        private readonly PermissionRepositoryInterface $repository,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PermissionsSynced::class, [self::class, 'handlePermissionsSynced']);
        $events->listen(RolesAssigned::class, [self::class, 'handleRolesAssigned']);
        $events->listen(RoleDeleted::class, [self::class, 'handleRoleDeleted']);
        $events->listen(UserDeleted::class, [self::class, 'handleUserDeleted']);
    }

    public function handlePermissionsSynced(PermissionsSynced $event): void
    {
        $roleId = $event->role->getKey();

        $this->log("Cache invalidation: role {$roleId} permissions synced.");

        $this->cacheManager->warmRole((int) $roleId);

        $userIds = $this->repository->getRoleUserIds((int) $roleId);

        foreach ($userIds as $userId) {
            $this->cacheManager->warmUser($userId);
        }

        $this->log("Cache invalidation complete: role {$roleId}, affected users: " . count($userIds));
    }

    public function handleRolesAssigned(RolesAssigned $event): void
    {
        $userId = $event->user->getKey();

        $this->log("Cache invalidation: user {$userId} roles assigned.");

        $this->cacheManager->warmUser((int) $userId);

        $this->rewarmUserRoleIndexes((int) $userId);
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

    private function rewarmUserRoleIndexes(int $userId): void
    {
        /** @var string $userModel */
        $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

        $table = config('permissions-redis.tables.model_has_roles', 'model_has_roles');

        $roleIds = DB::table($table)
            ->where('model_id', $userId)
            ->where('model_type', $userModel)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            $this->cacheManager->warmRole((int) $roleId);
        }
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
