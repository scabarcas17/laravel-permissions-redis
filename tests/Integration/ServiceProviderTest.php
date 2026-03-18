<?php

declare(strict_types=1);

use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Sebastian\LaravelPermissionsRedis\Resolver\PermissionResolver;

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

test('registers PermissionResolver as singleton', function () {
    $a = app(PermissionResolver::class);
    $b = app(PermissionResolver::class);

    expect($a)->toBe($b);
});

test('merges package config', function () {
    expect(config('permissions-redis.prefix'))->toBe('auth:')
        ->and(config('permissions-redis.ttl'))->toBe(86400)
        ->and(config('permissions-redis.tables.permissions'))->toBe('permissions');
});
