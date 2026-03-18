<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Middleware;

use Closure;
use Illuminate\Http\Request;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $role, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if ($user === null) {
            throw UnauthorizedException::notLoggedIn();
        }

        $roles = explode('|', $role);

        foreach ($roles as $r) {
            if ($this->resolver->hasRole($user->id, $r)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forRoles($roles);
    }
}
