<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\DTO;

class PermissionDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $id = null,
        public readonly ?string $group = null,
    ) {
    }
}
