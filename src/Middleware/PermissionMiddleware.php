<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Sebastian\LaravelPermissionsRedis\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    /**
     * @throws UnauthorizedException
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if ($user === null) {
            throw UnauthorizedException::notLoggedIn();
        }

        $permissions = explode('|', $permission);

        foreach ($permissions as $perm) {
            if ($this->resolver->hasPermission($user->id, $perm)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forPermissions($permissions);
    }
}
