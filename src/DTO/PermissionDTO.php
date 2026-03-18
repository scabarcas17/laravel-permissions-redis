<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\DTO;

class PermissionDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $id = null,
        public readonly ?string $group = null,
    ) {
    }

    public function __get(string $name): mixed
    {
        return $this->{$name} ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->{$name});
    }
}
