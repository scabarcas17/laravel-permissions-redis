<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Contracts;

use Illuminate\Support\Collection;
use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;

interface PermissionResolverInterface
{
    public function hasPermission(int|string $userId, string $permission, ?string $guard = null): bool;

    public function hasRole(int|string $userId, string $role, ?string $guard = null): bool;

    /** @return Collection<int, PermissionDTO> */
    public function getAllPermissions(int|string $userId, ?string $guard = null): Collection;

    /** @return Collection<int, string> */
    public function getAllRoles(int|string $userId, ?string $guard = null): Collection;

    public function flush(): void;

    public function flushUser(int|string $userId): void;
}
