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
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();

            return app(PermissionResolverInterface::class)->hasRole($userId, $role);
        });

        Blade::if('hasanyrole', static function (string $roles): bool {
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();
            $resolver = app(PermissionResolverInterface::class);

            foreach (explode('|', $roles) as $role) {
                if ($resolver->hasRole($userId, trim($role))) {
                    return true;
                }
            }

            return false;
        });

        Blade::if('hasallroles', static function (string $roles): bool {
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();
            $resolver = app(PermissionResolverInterface::class);

            foreach (explode('|', $roles) as $role) {
                if (!$resolver->hasRole($userId, trim($role))) {
                    return false;
                }
            }

            return true;
        });

        Blade::if('permission', static function (string $permission): bool {
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();

            return app(PermissionResolverInterface::class)->hasPermission($userId, $permission);
        });

        Blade::if('hasanypermission', static function (string $permissions): bool {
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();
            $resolver = app(PermissionResolverInterface::class);

            foreach (explode('|', $permissions) as $permission) {
                if ($resolver->hasPermission($userId, trim($permission))) {
                    return true;
                }
            }

            return false;
        });

        Blade::if('hasallpermissions', static function (string $permissions): bool {
            $user = auth()->user();

            if (!$user) {
                return false;
            }

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();
            $resolver = app(PermissionResolverInterface::class);

            foreach (explode('|', $permissions) as $permission) {
                if (!$resolver->hasPermission($userId, trim($permission))) {
                    return false;
                }
            }

            return true;
        });
    }
}
