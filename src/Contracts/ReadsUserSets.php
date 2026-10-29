<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Contracts;

/**
 * A repository that reads a user's permissions and roles together, in one
 * round trip. The resolver uses it when the repository implements it and
 * falls back to the separate reads of PermissionRepositoryInterface when it
 * does not, so a custom repository keeps working without it.
 */
interface ReadsUserSets
{
    /**
     * The user's encoded permissions and roles, or null when the user has no
     * cache entry. A user with no permissions has an entry, with empty lists.
     *
     * @return array{permissions: array<string>, roles: array<string>}|null
     */
    public function getUserSets(int|string $userId): ?array;
}
