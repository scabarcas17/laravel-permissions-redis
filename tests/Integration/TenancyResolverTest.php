<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Cache\TenantAwareRedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\PermissionsRedisServiceProvider;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\FakeTenantResolver;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;

function registerTenantedProvider(\Illuminate\Contracts\Foundation\Application $app, mixed $resolverConfig): void
{
    config()->set('permissions-redis.tenancy.enabled', true);
    config()->set('permissions-redis.tenancy.resolver', $resolverConfig);

    (new PermissionsRedisServiceProvider($app))->register();

    $app->instance(RedisPermissionRepository::class, new InMemoryPermissionRepository());
    $app->forgetInstance(PermissionRepositoryInterface::class);
}

test('stancl resolver returns null when Stancl\\Tenancy class is not available', function () {
    registerTenantedProvider($this->app, 'stancl');

    /** @var TenantAwareRedisPermissionRepository $repo */
    $repo = app(PermissionRepositoryInterface::class);

    expect($repo)->toBeInstanceOf(TenantAwareRedisPermissionRepository::class);

    $repo->setUserPermissions('user-x', ['web|test']);
    expect($repo->userHasPermission('user-x', 'web|test'))->toBeTrue();
});

test('custom tenant resolver class isolates data per tenant', function () {
    $this->app->singleton(FakeTenantResolver::class, fn () => FakeTenantResolver::closure());

    registerTenantedProvider($this->app, FakeTenantResolver::class);

    /** @var TenantAwareRedisPermissionRepository $repo */
    $repo = app(PermissionRepositoryInterface::class);

    FakeTenantResolver::$current = 'tenant-1';
    $repo->setUserPermissions(100, ['web|posts.create']);

    FakeTenantResolver::$current = 'tenant-2';
    $repo->setUserPermissions(100, ['web|posts.delete']);

    FakeTenantResolver::$current = 'tenant-1';
    expect($repo->getUserPermissions(100))->toBe(['web|posts.create']);

    FakeTenantResolver::$current = 'tenant-2';
    expect($repo->getUserPermissions(100))->toBe(['web|posts.delete']);
});

test('unknown resolver config falls back to null-tenant closure (data not tenant-scoped)', function () {
    registerTenantedProvider($this->app, 'NonExistent\\Resolver\\Class');

    /** @var TenantAwareRedisPermissionRepository $repo */
    $repo = app(PermissionRepositoryInterface::class);

    $repo->setUserPermissions('u', ['web|p']);
    expect($repo->userHasPermission('u', 'web|p'))->toBeTrue();
});

test('TenantAware replaceSetBatch prefixes user and role suffixes with tenant key', function () {
    $this->app->singleton(FakeTenantResolver::class, fn () => FakeTenantResolver::closure());
    registerTenantedProvider($this->app, FakeTenantResolver::class);

    /** @var TenantAwareRedisPermissionRepository $repo */
    $repo = app(PermissionRepositoryInterface::class);

    FakeTenantResolver::$current = 'acme';

    $repo->replaceSetBatch([
        'user:42:permissions' => ['web|users.create'],
        'user:42:roles'       => ['web|admin'],
        'role:7:permissions'  => ['web|posts.edit'],
        'role:7:users'        => ['42'],
    ]);

    /** @var InMemoryPermissionRepository $inner */
    $inner = $this->app->get(RedisPermissionRepository::class);

    expect($inner->getUserPermissions('t:acme:42'))->toBe(['web|users.create'])
        ->and($inner->getUserRoles('t:acme:42'))->toBe(['web|admin'])
        ->and($inner->getRoleUserIds('t:acme:7'))->toBe([42]);
});
