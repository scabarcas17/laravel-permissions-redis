<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\DTO\PermissionDTO;

test('constructs with name only', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect($dto->name)->toBe('users.create')
        ->and($dto->id)->toBeNull()
        ->and($dto->group)->toBeNull();
});

test('constructs with all properties', function () {
    $dto = new PermissionDTO(name: 'users.create', id: 5, group: 'users');

    expect($dto->name)->toBe('users.create')
        ->and($dto->id)->toBe(5)
        ->and($dto->group)->toBe('users');
});

test('properties are readonly', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect(fn () => $dto->name = 'changed')->toThrow(Error::class);
});
