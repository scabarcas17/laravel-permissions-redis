<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Blade;

use Illuminate\Support\Facades\Blade;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;

class BladeDirectivesRegistrar
{
    public static function register(): void
    {
        Blade::if('role', static function (string $role): bool {
            return self::resolveForUser(fn (int $userId, string $guard, PermissionResolverInterface $resolver) => $resolver->hasRole($userId, $role, $guard));
        });

        Blade::if('hasanyrole', static function (string $roles): bool {
            return self::resolveForUser(function (int $userId, string $guard, PermissionResolverInterface $resolver) use ($roles): bool {
                foreach (explode('|', $roles) as $role) {
                    if ($resolver->hasRole($userId, trim($role), $guard)) {
                        return true;
                    }
                }

                return false;
            });
        });

        Blade::if('hasallroles', static function (string $roles): bool {
            return self::resolveForUser(function (int $userId, string $guard, PermissionResolverInterface $resolver) use ($roles): bool {
                foreach (explode('|', $roles) as $role) {
                    if (!$resolver->hasRole($userId, trim($role), $guard)) {
                        return false;
                    }
                }

                return true;
            });
        });

        Blade::if('permission', static function (string $permission): bool {
            return self::resolveForUser(fn (int $userId, string $guard, PermissionResolverInterface $resolver) => $resolver->hasPermission($userId, $permission, $guard));
        });

        Blade::if('hasanypermission', static function (string $permissions): bool {
            return self::resolveForUser(function (int $userId, string $guard, PermissionResolverInterface $resolver) use ($permissions): bool {
                foreach (explode('|', $permissions) as $permission) {
                    if ($resolver->hasPermission($userId, trim($permission), $guard)) {
                        return true;
                    }
                }

                return false;
            });
        });

        Blade::if('hasallpermissions', static function (string $permissions): bool {
            return self::resolveForUser(function (int $userId, string $guard, PermissionResolverInterface $resolver) use ($permissions): bool {
                foreach (explode('|', $permissions) as $permission) {
                    if (!$resolver->hasPermission($userId, trim($permission), $guard)) {
                        return false;
                    }
                }

                return true;
            });
        });
    }

    /** @param callable(int, string, PermissionResolverInterface): bool $callback */
    private static function resolveForUser(callable $callback): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        /** @var int $userId */
        $userId = $user->getAuthIdentifier();
        $guard = auth()->getDefaultDriver();

        return $callback($userId, $guard, app(PermissionResolverInterface::class));
    }
}
