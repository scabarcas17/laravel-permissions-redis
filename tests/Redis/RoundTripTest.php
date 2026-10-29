<?php

declare(strict_types=1);

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;

// Every Redis command is a network round trip in production, so what counts
// is how many a request sends, not what each one costs on localhost. The
// request below has the shape of the benchmark app's: 27 distinct permission
// checks, 4 role checks, getAllPermissions() and getRoleNames(). 4.2.0 sent
// 67 commands for it: an EXISTS and a SISMEMBER per distinct check.

test('a check-heavy request sends one EXISTS, one SMEMBERS per set and one HMGET', function () {
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

    expect(array_count_values($commands))->toEqual(['exists' => 1, 'smembers' => 2, 'hmget' => 1]);
});
