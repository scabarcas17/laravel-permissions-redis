<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\TenantAwareRedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;

function createTenantRepo(InMemoryPermissionRepository $inner, string|int|null $tenantId): TenantAwareRedisPermissionRepository
{
    return new TenantAwareRedisPermissionRepository(
        // @phpstan-ignore argument.type
        $inner,
        fn () => $tenantId,
    );
}

// ─── Tenant isolation ───

test('permissions are isolated between tenants', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'tenant-1');
    $tenant2Repo = createTenantRepo($inner, 'tenant-2');

    $tenant1Repo->setUserPermissions(1, ['web|posts.create']);
    $tenant2Repo->setUserPermissions(1, ['web|posts.delete']);

    expect($tenant1Repo->userHasPermission(1, 'web|posts.create'))->toBeTrue();
    expect($tenant1Repo->userHasPermission(1, 'web|posts.delete'))->toBeFalse();

    expect($tenant2Repo->userHasPermission(1, 'web|posts.delete'))->toBeTrue();
    expect($tenant2Repo->userHasPermission(1, 'web|posts.create'))->toBeFalse();
});

test('roles are isolated between tenants', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'tenant-1');
    $tenant2Repo = createTenantRepo($inner, 'tenant-2');

    $tenant1Repo->setUserRoles(1, ['web|admin']);
    $tenant2Repo->setUserRoles(1, ['web|editor']);

    expect($tenant1Repo->userHasRole(1, 'web|admin'))->toBeTrue();
    expect($tenant1Repo->userHasRole(1, 'web|editor'))->toBeFalse();

    expect($tenant2Repo->userHasRole(1, 'web|editor'))->toBeTrue();
    expect($tenant2Repo->userHasRole(1, 'web|admin'))->toBeFalse();
});

test('getUserPermissions returns only current tenant data', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'acme');
    $tenant2Repo = createTenantRepo($inner, 'globex');

    $tenant1Repo->setUserPermissions(1, ['web|users.manage', 'web|posts.create']);
    $tenant2Repo->setUserPermissions(1, ['web|posts.view']);

    expect($tenant1Repo->getUserPermissions(1))->toBe(['web|users.manage', 'web|posts.create']);
    expect($tenant2Repo->getUserPermissions(1))->toBe(['web|posts.view']);
});

test('getUserRoles returns only current tenant data', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 1);
    $tenant2Repo = createTenantRepo($inner, 2);

    $tenant1Repo->setUserRoles(1, ['web|super-admin']);
    $tenant2Repo->setUserRoles(1, ['web|viewer']);

    expect($tenant1Repo->getUserRoles(1))->toBe(['web|super-admin']);
    expect($tenant2Repo->getUserRoles(1))->toBe(['web|viewer']);
});

// ─── Cache existence ───

test('userCacheExists is tenant-scoped', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'tenant-1');
    $tenant2Repo = createTenantRepo($inner, 'tenant-2');

    $tenant1Repo->setUserPermissions(1, ['web|posts.create']);

    expect($tenant1Repo->userCacheExists(1))->toBeTrue();
    expect($tenant2Repo->userCacheExists(1))->toBeFalse();
});

// ─── Cache deletion ───

test('deleteUserCache only affects current tenant', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'tenant-1');
    $tenant2Repo = createTenantRepo($inner, 'tenant-2');

    $tenant1Repo->setUserPermissions(1, ['web|posts.create']);
    $tenant2Repo->setUserPermissions(1, ['web|posts.delete']);

    $tenant1Repo->deleteUserCache(1);

    expect($tenant1Repo->userCacheExists(1))->toBeFalse();
    expect($tenant2Repo->userCacheExists(1))->toBeTrue();
    expect($tenant2Repo->getUserPermissions(1))->toBe(['web|posts.delete']);
});

// ─── Null tenant (fallback behavior) ───

test('null tenant resolver uses raw user ID without prefix', function () {
    $inner = new InMemoryPermissionRepository();
    $repo = createTenantRepo($inner, null);

    $repo->setUserPermissions(1, ['web|posts.create']);

    // With null tenant, the key is just the string cast of userId
    expect($repo->userHasPermission(1, 'web|posts.create'))->toBeTrue();
});

// ─── UUID user IDs with tenancy ───

test('tenant isolation works with UUID user IDs', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 'tenant-1');
    $tenant2Repo = createTenantRepo($inner, 'tenant-2');

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $tenant1Repo->setUserPermissions($uuid, ['web|posts.create']);
    $tenant2Repo->setUserPermissions($uuid, ['web|posts.delete']);

    expect($tenant1Repo->userHasPermission($uuid, 'web|posts.create'))->toBeTrue();
    expect($tenant1Repo->userHasPermission($uuid, 'web|posts.delete'))->toBeFalse();

    expect($tenant2Repo->userHasPermission($uuid, 'web|posts.delete'))->toBeTrue();
    expect($tenant2Repo->userHasPermission($uuid, 'web|posts.create'))->toBeFalse();
});

// ─── Integer tenant IDs ───

test('integer tenant IDs work correctly', function () {
    $inner = new InMemoryPermissionRepository();

    $tenant1Repo = createTenantRepo($inner, 100);
    $tenant2Repo = createTenantRepo($inner, 200);

    $tenant1Repo->setUserPermissions(1, ['web|posts.create']);
    $tenant2Repo->setUserPermissions(1, ['web|posts.edit']);

    expect($tenant1Repo->getUserPermissions(1))->toBe(['web|posts.create']);
    expect($tenant2Repo->getUserPermissions(1))->toBe(['web|posts.edit']);
});
