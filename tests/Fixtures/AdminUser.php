<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions;

/**
 * Second user model fixture used to exercise the multi-model configuration.
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 */
class AdminUser extends Model implements Authenticatable
{
    use HasRedisPermissions;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = ['id'];

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
