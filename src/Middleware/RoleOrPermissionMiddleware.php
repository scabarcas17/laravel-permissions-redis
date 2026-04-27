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

        /** @var int|string $userId */
        $userId = $user->getAuthIdentifier();
        $guardName = $guard ?? auth()->getDefaultDriver();

        if (str_contains($roleOrPermission, '&')) {
            $items = array_map('trim', explode('&', $roleOrPermission));

            foreach ($items as $item) {
                if (!$this->resolver->hasPermission($userId, $item, $guardName) && !$this->resolver->hasRole($userId, $item, $guardName)) {
                    throw UnauthorizedException::forRolesOrPermissions($items);
                }
            }

            return $next($request);
        }

        $rolesOrPermissions = array_map('trim', explode('|', $roleOrPermission));

        foreach ($rolesOrPermissions as $item) {
            if ($this->resolver->hasPermission($userId, $item, $guardName) || $this->resolver->hasRole($userId, $item, $guardName)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forRolesOrPermissions($rolesOrPermissions);
    }
}
