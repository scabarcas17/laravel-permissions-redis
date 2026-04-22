<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Blade;

use Illuminate\Support\Facades\Blade;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;

class BladeDirectivesRegistrar
{
    public static function register(): void
    {
        Blade::if('role', static function (string $role, ?string $guard = null): bool {
            return self::resolveForUser(
                fn (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver): bool => $resolver->hasRole($userId, $role, $resolvedGuard),
                $guard,
            );
        });

        Blade::if('hasanyrole', static function (string $roles, ?string $guard = null): bool {
            return self::resolveForUser(
                function (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver) use ($roles): bool {
                    foreach (explode('|', $roles) as $role) {
                        if ($resolver->hasRole($userId, trim($role), $resolvedGuard)) {
                            return true;
                        }
                    }

                    return false;
                },
                $guard,
            );
        });

        Blade::if('hasallroles', static function (string $roles, ?string $guard = null): bool {
            return self::resolveForUser(
                function (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver) use ($roles): bool {
                    foreach (explode('|', $roles) as $role) {
                        if (!$resolver->hasRole($userId, trim($role), $resolvedGuard)) {
                            return false;
                        }
                    }

                    return true;
                },
                $guard,
            );
        });

        Blade::if('permission', static function (string $permission, ?string $guard = null): bool {
            return self::resolveForUser(
                fn (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver): bool => $resolver->hasPermission($userId, $permission, $resolvedGuard),
                $guard,
            );
        });

        Blade::if('hasanypermission', static function (string $permissions, ?string $guard = null): bool {
            return self::resolveForUser(
                function (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver) use ($permissions): bool {
                    foreach (explode('|', $permissions) as $permission) {
                        if ($resolver->hasPermission($userId, trim($permission), $resolvedGuard)) {
                            return true;
                        }
                    }

                    return false;
                },
                $guard,
            );
        });

        Blade::if('hasallpermissions', static function (string $permissions, ?string $guard = null): bool {
            return self::resolveForUser(
                function (int|string $userId, string $resolvedGuard, PermissionResolverInterface $resolver) use ($permissions): bool {
                    foreach (explode('|', $permissions) as $permission) {
                        if (!$resolver->hasPermission($userId, trim($permission), $resolvedGuard)) {
                            return false;
                        }
                    }

                    return true;
                },
                $guard,
            );
        });
    }

    /** @param callable(int|string, string, PermissionResolverInterface): bool $callback */
    private static function resolveForUser(callable $callback, ?string $guardOverride = null): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        /** @var int|string $userId */
        $userId = $user->getAuthIdentifier();
        $guard = $guardOverride ?? auth()->getDefaultDriver();

        return $callback($userId, $guard, app(PermissionResolverInterface::class));
    }
}
