<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Contracts;

use Illuminate\Support\Collection;
use Sebastian\LaravelPermissionsRedis\DTO\PermissionDTO;

interface PermissionResolverInterface
{
    public function hasPermission(int $userId, string $permission): bool;

    public function hasRole(int $userId, string $role): bool;

    /** @return Collection<int, PermissionDTO> */
    public function getAllPermissions(int $userId): Collection;

    /** @return Collection<int, string> */
    public function getAllRoles(int $userId): Collection;

    public function flush(): void;

    public function flushUser(int $userId): void;
}
