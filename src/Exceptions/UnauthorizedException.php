<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    /** @var array<string> */
    private array $requiredItems;

    /**
     * @param array<string> $requiredItems
     */
    public function __construct(int $statusCode, string $message = '', array $requiredItems = [])
    {
        $this->requiredItems = $requiredItems;

        parent::__construct($statusCode, $message);
    }

    /**
     * @param array<string> $permissions
     */
    public static function forPermissions(array $permissions): self
    {
        $message = 'User does not have the required permissions [' . implode(', ', $permissions) . '].';

        return new self(403, $message, $permissions);
    }

    /**
     * @param array<string> $roles
     */
    public static function forRoles(array $roles): self
    {
        $message = 'User does not have the required roles [' . implode(', ', $roles) . '].';

        return new self(403, $message, $roles);
    }

    /**
     * @param array<string> $rolesOrPermissions
     */
    public static function forRolesOrPermissions(array $rolesOrPermissions): self
    {
        $message = 'User does not have any of the required roles or permissions [' . implode(', ', $rolesOrPermissions) . '].';

        return new self(403, $message, $rolesOrPermissions);
    }

    public static function notLoggedIn(): self
    {
        return new self(403, 'User is not authenticated.');
    }

    /** @return array<string> */
    public function getRequiredItems(): array
    {
        return $this->requiredItems;
    }
}
