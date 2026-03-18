<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException;
use Scabarcas\LaravelPermissionsRedis\Middleware\PermissionMiddleware;
use Scabarcas\LaravelPermissionsRedis\Middleware\RoleMiddleware;
use Scabarcas\LaravelPermissionsRedis\Middleware\RoleOrPermissionMiddleware;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

function makeRequestWithUser(?User $user = null): Request
{
    $request = Request::create('/test');

    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}

function passThrough(): Closure
{
    return fn (Request $request) => new \Illuminate\Http\Response('OK');
}

// ─── PermissionMiddleware ───

describe('PermissionMiddleware', function () {
    test('allows request when user has single permission', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new PermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.create');

        expect($response->getContent())->toBe('OK');
    });

    test('allows request when user has one of pipe-separated permissions', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(false);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.edit')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new PermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.create|users.edit');

        expect($response->getContent())->toBe('OK');
    });

    test('throws UnauthorizedException when user lacks permissions', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.delete')->once()->andReturn(false);

        $user = new User();
        $user->id = 1;

        $middleware = new PermissionMiddleware($resolver);
        $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.delete');
    })->throws(UnauthorizedException::class);

    test('throws UnauthorizedException when user is not authenticated', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);

        $middleware = new PermissionMiddleware($resolver);
        $middleware->handle(makeRequestWithUser(), passThrough(), 'users.create');
    })->throws(UnauthorizedException::class, 'User is not authenticated.');

    test('requires all permissions with AND operator (&)', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(true);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.edit')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new PermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.create&users.edit');

        expect($response->getContent())->toBe('OK');
    });

    test('throws when user lacks one of AND permissions', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(true);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.delete')->once()->andReturn(false);

        $user = new User();
        $user->id = 1;

        $middleware = new PermissionMiddleware($resolver);
        $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.create&users.delete');
    })->throws(UnauthorizedException::class);
});

// ─── RoleMiddleware ───

describe('RoleMiddleware', function () {
    test('allows request when user has single role', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin');

        expect($response->getContent())->toBe('OK');
    });

    test('allows request when user has one of pipe-separated roles', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(false);
        $resolver->shouldReceive('hasRole')->with(1, 'editor')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin|editor');

        expect($response->getContent())->toBe('OK');
    });

    test('throws UnauthorizedException when user lacks roles', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(false);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleMiddleware($resolver);
        $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin');
    })->throws(UnauthorizedException::class);

    test('throws UnauthorizedException when user is not authenticated', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);

        $middleware = new RoleMiddleware($resolver);
        $middleware->handle(makeRequestWithUser(), passThrough(), 'admin');
    })->throws(UnauthorizedException::class, 'User is not authenticated.');

    test('requires all roles with AND operator (&)', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(true);
        $resolver->shouldReceive('hasRole')->with(1, 'editor')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin&editor');

        expect($response->getContent())->toBe('OK');
    });

    test('throws when user lacks one of AND roles', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(true);
        $resolver->shouldReceive('hasRole')->with(1, 'superadmin')->once()->andReturn(false);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleMiddleware($resolver);
        $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin&superadmin');
    })->throws(UnauthorizedException::class);
});

// ─── RoleOrPermissionMiddleware ───

describe('RoleOrPermissionMiddleware', function () {
    test('allows request when user has matching permission', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleOrPermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'users.create');

        expect($response->getContent())->toBe('OK');
    });

    test('allows request when user has matching role', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'admin')->once()->andReturn(false);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleOrPermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin');

        expect($response->getContent())->toBe('OK');
    });

    test('throws when user has neither role nor permission', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'admin')->once()->andReturn(false);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(false);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleOrPermissionMiddleware($resolver);
        $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin');
    })->throws(UnauthorizedException::class);

    test('throws UnauthorizedException when user is not authenticated', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);

        $middleware = new RoleOrPermissionMiddleware($resolver);
        $middleware->handle(makeRequestWithUser(), passThrough(), 'admin');
    })->throws(UnauthorizedException::class, 'User is not authenticated.');

    test('requires all items with AND operator (&)', function () {
        $resolver = Mockery::mock(PermissionResolverInterface::class);
        $resolver->shouldReceive('hasPermission')->with(1, 'admin')->once()->andReturn(false);
        $resolver->shouldReceive('hasRole')->with(1, 'admin')->once()->andReturn(true);
        $resolver->shouldReceive('hasPermission')->with(1, 'users.create')->once()->andReturn(true);

        $user = new User();
        $user->id = 1;

        $middleware = new RoleOrPermissionMiddleware($resolver);
        $response = $middleware->handle(makeRequestWithUser($user), passThrough(), 'admin&users.create');

        expect($response->getContent())->toBe('OK');
    });
});
