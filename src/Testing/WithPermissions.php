<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Testing;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;

/**
 * Testing helpers for applications using laravel-permissions-redis.
 *
 * Use this trait in your test classes (PHPUnit or Pest) to simplify
 * permission and role setup in tests.
 *
 * @mixin \Illuminate\Foundation\Testing\TestCase
 */
trait WithPermissions
{
    /**
     * Create permissions from an array of names.
     *
     * @param array<string|BackedEnum> $permissions
     *
     * @return array<Permission>
     */
    protected function seedPermissions(array $permissions, string $guard = 'web'): array
    {
        return array_map(
            fn (string|BackedEnum $name): Permission => Permission::findOrCreate(
                $name instanceof BackedEnum ? (string) $name->value : $name,
                $guard,
            ),
            $permissions,
        );
    }

    /**
     * Create roles with optional permission assignments.
     *
     * @param array<string, array<string>> $roles Key = role name, value = permission names
     *
     * @return array<string, Role>
     */
    protected function seedRoles(array $roles, string $guard = 'web'): array
    {
        $created = [];

        foreach ($roles as $roleName => $permissions) {
            if (!is_string($roleName) || $roleName === '') {
                throw new InvalidArgumentException(
                    'seedRoles() expects an associative array keyed by role name.'
                );
            }

            $role = Role::findOrCreate($roleName, $guard);

            if (is_array($permissions) && $permissions !== []) {
                $this->seedPermissions($permissions, $guard);
                $role->syncPermissions($permissions);
            }

            $created[$roleName] = $role;
        }

        return $created;
    }

    /**
     * Act as a user with specific permissions (seeds + assigns + warms cache).
     *
     * @param Authenticatable&\Illuminate\Database\Eloquent\Model $user
     * @param array<string|BackedEnum>                            $permissions
     */
    protected function actingAsWithPermissions(Authenticatable $user, array $permissions, string $guard = 'web'): static
    {
        $this->seedPermissions($permissions, $guard);

        /** @var \Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions $user */
        $user->givePermissionTo(...$permissions);

        return $this->actingAs($user, $guard);
    }

    /**
     * Act as a user with specific roles (seeds + assigns + warms cache).
     *
     * @param Authenticatable&\Illuminate\Database\Eloquent\Model $user
     * @param array<string|BackedEnum>                            $roles
     */
    protected function actingAsWithRoles(Authenticatable $user, array $roles, string $guard = 'web'): static
    {
        foreach ($roles as $role) {
            Role::findOrCreate(
                $role instanceof BackedEnum ? (string) $role->value : $role,
                $guard,
            );
        }

        /** @var \Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions $user */
        $user->assignRole(...$roles);

        return $this->actingAs($user, $guard);
    }

    /**
     * Flush all permission caches (Redis + in-memory).
     */
    protected function flushPermissionCache(): void
    {
        app(PermissionRepositoryInterface::class)->flushAll();
        app(PermissionResolverInterface::class)->flush();
    }
}
