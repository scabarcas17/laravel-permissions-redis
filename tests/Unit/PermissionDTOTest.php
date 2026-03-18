<?php

declare(strict_types=1);

use Sebastian\LaravelPermissionsRedis\DTO\PermissionDTO;

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

test('magic __get returns null for undefined properties', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect($dto->__get('nonexistent'))->toBeNull();
});

test('magic __isset returns false for null properties', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect(isset($dto->name))->toBeTrue()
        ->and(isset($dto->id))->toBeFalse()
        ->and(isset($dto->group))->toBeFalse();
});

test('properties are readonly', function () {
    $dto = new PermissionDTO(name: 'users.create');

    expect(fn () => $dto->name = 'changed')->toThrow(Error::class);
});
