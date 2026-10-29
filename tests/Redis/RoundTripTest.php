<?php

declare(strict_types=1);

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;

// Every Redis command is a network round trip in production, so what counts
// is how many a request sends, not what each one costs on localhost. The
// request below has the shape of the benchmark app's: 27 distinct permission
// checks, 4 role checks, getAllPermissions() and getRoleNames(). 4.2.0 sent
// 67 commands for it: an EXISTS and a SISMEMBER per distinct check.

test('a check-heavy request sends one EVAL for both sets and one HMGET', function () {
    $permissions = [
        'users.view', 'users.create', 'users.edit', 'users.delete',
        'posts.view', 'posts.create', 'posts.edit', 'posts.delete',
        'comments.view', 'comments.create', 'comments.edit', 'comments.delete',
        'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
        'tags.view', 'tags.create', 'tags.edit', 'tags.delete',
        'settings.view', 'settings.edit', 'reports.view', 'reports.export',
        'dashboard.view', 'media.upload', 'media.delete',
    ];

    $repository = app(PermissionRepositoryInterface::class);
    $repository->setUserPermissions(1, array_map(fn (string $p): string => "web|{$p}", array_slice($permissions, 0, 18)));
    $repository->setUserRoles(1, ['web|admin', 'web|editor']);

    $resolver = app(PermissionResolverInterface::class);
    $resolver->flush();

    $commands = [];
    Redis::connection('default')->setEventDispatcher(app('events'));
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });

    foreach ($permissions as $permission) {
        $resolver->hasPermission(1, $permission);
    }

    foreach (['admin', 'editor', 'viewer', 'nonexistent'] as $role) {
        $resolver->hasRole(1, $role);
    }

    $resolver->getAllPermissions(1);
    $resolver->getAllRoles(1);

    expect(array_count_values($commands))->toEqual(['eval' => 1, 'hmget' => 1]);
});

test('a server that refuses scripts gets the two sets with two SMEMBERS', function () {
    // An ACL user without @scripting: the server answers EVAL with NOPERM.
    $admin = Redis::connection('default');
    $admin->command('acl', ['setuser', 'lpr_noscript', 'on', '>noscript', '~*', '+@all', '-@scripting']);

    try {
        config()->set('database.redis.noscript', array_merge(
            (array) config('database.redis.default'),
            ['username' => 'lpr_noscript', 'password' => 'noscript'],
        ));
        config()->set('permissions-redis.redis_connection', 'noscript');

        // The manager reads its config once; a fresh one sees the new connection.
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');

        $repository = new RedisPermissionRepository();
        $repository->setUserPermissions(1, ['web|posts.edit']);
        $repository->setUserRoles(1, ['web|editor']);

        $commands = [];
        $count = function (object $event) use (&$commands): void {
            /** @var CommandExecuted $event */
            $commands[] = strtolower($event->command);
        };

        Redis::connection('noscript')->setEventDispatcher(app('events'));
        Event::listen(CommandExecuted::class, $count);

        // The refused EVAL is a CommandFailed under predis (it throws) where
        // the framework has that event, a CommandExecuted under phpredis
        // (it answers false).
        if (class_exists('Illuminate\\Redis\\Events\\CommandFailed')) {
            Event::listen('Illuminate\\Redis\\Events\\CommandFailed', $count);
        }

        expect($repository->getUserSets(1))->toBe(['permissions' => ['web|posts.edit'], 'roles' => ['web|editor']])
            ->and($repository->getUserSets(2))->toBeNull();

        $counts = array_count_values($commands);

        // Two SMEMBERS for each read, and EVAL is not asked again once refused.
        expect($counts['smembers'] ?? 0)->toBe(4)
            ->and($counts['eval'] ?? 0)->toBeLessThanOrEqual(1);
    } finally {
        $admin->command('acl', ['deluser', 'lpr_noscript']);
    }
});
