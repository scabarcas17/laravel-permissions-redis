<?php

declare(strict_types=1);

use Sebastian\LaravelPermissionsRedis\Exceptions\UnauthorizedException;

test('forPermissions creates 403 with permissions list', function () {
    $exception = UnauthorizedException::forPermissions(['users.create', 'users.edit']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toContain('users.create')
        ->and($exception->getMessage())->toContain('users.edit')
        ->and($exception->getRequiredItems())->toBe(['users.create', 'users.edit']);
});

test('forRoles creates 403 with roles list', function () {
    $exception = UnauthorizedException::forRoles(['admin', 'editor']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toContain('admin')
        ->and($exception->getMessage())->toContain('editor')
        ->and($exception->getRequiredItems())->toBe(['admin', 'editor']);
});

test('forRolesOrPermissions creates 403 with combined list', function () {
    $exception = UnauthorizedException::forRolesOrPermissions(['admin', 'users.create']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toContain('admin')
        ->and($exception->getMessage())->toContain('users.create')
        ->and($exception->getRequiredItems())->toBe(['admin', 'users.create']);
});

test('notLoggedIn creates 403 with authentication message', function () {
    $exception = UnauthorizedException::notLoggedIn();

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toContain('not authenticated')
        ->and($exception->getRequiredItems())->toBe([]);
});
