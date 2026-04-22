<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\PermissionsRedisServiceProvider;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind an in-memory repository by default so Eloquent hooks in the
        // Permission/Role models do not attempt to reach a real Redis instance
        // during tests that do not explicitly rebind the interface.
        $this->app->singleton(
            PermissionRepositoryInterface::class,
            InMemoryPermissionRepository::class,
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionsRedisServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('permissions-redis.user_model', User::class);
        $app['config']->set('permissions-redis.register_gate', false);
        $app['config']->set('permissions-redis.register_middleware', false);
        $app['config']->set('permissions-redis.warm_on_login', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
