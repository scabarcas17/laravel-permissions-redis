<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;

test('constructs with name only', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect($dto->name)->toBe('users.create')
        ->and($dto->group)->toBeNull()
        ->and($dto->guard)->toBeNull();
});

test('constructs with all properties', function () {
    $dto = new PermissionDTO(name: 'users.create', group: 'users', guard: 'web');

    expect($dto->name)->toBe('users.create')
        ->and($dto->group)->toBe('users')
        ->and($dto->guard)->toBe('web');
});

test('properties are readonly', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect(fn () => $dto->name = 'changed')->toThrow(Error::class);
});
