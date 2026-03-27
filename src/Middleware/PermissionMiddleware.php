<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Middleware;

use Closure;
use Illuminate\Http\Request;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if ($user === null) {
            throw UnauthorizedException::notLoggedIn();
        }

        /** @var int $userId */
        $userId = $user->getAuthIdentifier();
        $guardName = $guard ?? auth()->getDefaultDriver();

        if (str_contains($permission, '&')) {
            $permissions = array_map('trim', explode('&', $permission));

            foreach ($permissions as $perm) {
                if (!$this->resolver->hasPermission($userId, $perm, $guardName)) {
                    throw UnauthorizedException::forPermissions($permissions);
                }
            }

            return $next($request);
        }

        $permissions = explode('|', $permission);

        foreach ($permissions as $perm) {
            if ($this->resolver->hasPermission($userId, $perm, $guardName)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forPermissions($permissions);
    }
}
