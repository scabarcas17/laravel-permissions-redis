<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException;

test('forPermissions creates 403 with generic message and required items', function () {
    $exception = UnauthorizedException::forPermissions(['users.create', 'users.edit']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toBe('User does not have the required permissions.')
        ->and($exception->getRequiredItems())->toBe(['users.create', 'users.edit']);
});

test('forRoles creates 403 with generic message and required items', function () {
    $exception = UnauthorizedException::forRoles(['admin', 'editor']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toBe('User does not have the required roles.')
        ->and($exception->getRequiredItems())->toBe(['admin', 'editor']);
});

test('forRolesOrPermissions creates 403 with generic message and required items', function () {
    $exception = UnauthorizedException::forRolesOrPermissions(['admin', 'users.create']);

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->getMessage())->toBe('User does not have any of the required roles or permissions.')
        ->and($exception->getRequiredItems())->toBe(['admin', 'users.create']);
});

test('notLoggedIn creates 401 with authentication message', function () {
    $exception = UnauthorizedException::notLoggedIn();

    expect($exception->getStatusCode())->toBe(401)
        ->and($exception->getMessage())->toContain('not authenticated')
        ->and($exception->getRequiredItems())->toBe([]);
});
