<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;

test('binds PermissionRepositoryInterface to RedisPermissionRepository', function () {
    $instance = app(PermissionRepositoryInterface::class);

    expect($instance)->toBeInstanceOf(RedisPermissionRepository::class);
});

test('binds PermissionResolverInterface to PermissionResolver', function () {
    $instance = app(PermissionResolverInterface::class);

    expect($instance)->toBeInstanceOf(PermissionResolver::class);
});

test('registers AuthorizationCacheManager as singleton', function () {
    $a = app(AuthorizationCacheManager::class);
    $b = app(AuthorizationCacheManager::class);

    expect($a)->toBe($b);
});

test('registers PermissionResolver as singleton via interface', function () {
    $a = app(PermissionResolverInterface::class);
    $b = app(PermissionResolverInterface::class);

    expect($a)->toBe($b);
});

test('merges package config', function () {
    expect(config('permissions-redis.prefix'))->toBe('auth:')
        ->and(config('permissions-redis.ttl'))->toBe(86400)
        ->and(config('permissions-redis.tables.permissions'))->toBe('permissions');
});
