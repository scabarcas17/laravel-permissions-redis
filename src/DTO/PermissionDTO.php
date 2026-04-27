<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\DTO;

readonly class PermissionDTO
{
    public function __construct(
        public string $name,
        public ?string $group = null,
        public ?string $guard = null,
    ) {
    }
}
