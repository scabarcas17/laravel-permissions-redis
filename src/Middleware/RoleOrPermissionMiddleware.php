<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Middleware;

use Closure;
use Illuminate\Http\Request;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class RoleOrPermissionMiddleware
{
    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $roleOrPermission, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if ($user === null) {
            throw UnauthorizedException::notLoggedIn();
        }

        /** @var int $userId */
        $userId = $user->getAuthIdentifier();

        if (str_contains($roleOrPermission, '&')) {
            $items = array_map('trim', explode('&', $roleOrPermission));

            foreach ($items as $item) {
                if (!$this->resolver->hasPermission($userId, $item) && !$this->resolver->hasRole($userId, $item)) {
                    throw UnauthorizedException::forRolesOrPermissions($items);
                }
            }

            return $next($request);
        }

        $rolesOrPermissions = explode('|', $roleOrPermission);

        foreach ($rolesOrPermissions as $item) {
            if ($this->resolver->hasPermission($userId, $item)) {
                return $next($request);
            }

            if ($this->resolver->hasRole($userId, $item)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forRolesOrPermissions($rolesOrPermissions);
    }
}
