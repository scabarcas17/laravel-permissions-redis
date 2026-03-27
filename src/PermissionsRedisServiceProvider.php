<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Scabarcas\LaravelPermissionsRedis\Blade\BladeDirectivesRegistrar;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Commands\CacheStatsCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\FlushCacheCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\WarmCacheCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\WarmUserCacheCommand;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Listeners\CacheInvalidator;
use Scabarcas\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Scabarcas\LaravelPermissionsRedis\Middleware\PermissionMiddleware;
use Scabarcas\LaravelPermissionsRedis\Middleware\RoleMiddleware;
use Scabarcas\LaravelPermissionsRedis\Middleware\RoleOrPermissionMiddleware;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;

class PermissionsRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permissions-redis.php', 'permissions-redis');

        $this->app->singleton(PermissionRepositoryInterface::class, RedisPermissionRepository::class);
        $this->app->singleton(AuthorizationCacheManager::class);
        $this->app->singleton(PermissionResolverInterface::class, PermissionResolver::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->publishAssets();
        $this->registerCommands();
        $this->registerEventSubscriber();

        if (config('permissions-redis.register_gate', true)) {
            $this->registerGateIntegration();
        }

        if (config('permissions-redis.register_middleware', true)) {
            $this->registerMiddleware();
        }

        if (config('permissions-redis.register_blade_directives', true)) {
            BladeDirectivesRegistrar::register();
        }

        if (config('permissions-redis.warm_on_login', true)) {
            $this->registerWarmOnLogin();
        }
    }

    private function publishAssets(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/permissions-redis.php' => config_path('permissions-redis.php'),
            ], 'permissions-redis-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'permissions-redis-migrations');

            $this->publishes([
                __DIR__ . '/../config/permissions-redis.php' => config_path('permissions-redis.php'),
                __DIR__ . '/../database/migrations/'         => database_path('migrations'),
            ]);
        }
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WarmCacheCommand::class,
                WarmUserCacheCommand::class,
                FlushCacheCommand::class,
                CacheStatsCommand::class,
            ]);
        }
    }

    private function registerGateIntegration(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            /** @var string $userModel */
            $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

            if (!$user instanceof $userModel) {
                return null;
            }

            /** @var PermissionResolverInterface $resolver */
            $resolver = app(PermissionResolverInterface::class);

            /** @var int $userId */
            $userId = $user->getAuthIdentifier();

            $guard = auth()->getDefaultDriver();

            if ($resolver->hasPermission($userId, $ability, $guard)) {
                return true;
            }

            return null;
        });
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('permission', PermissionMiddleware::class);
        $router->aliasMiddleware('role', RoleMiddleware::class);
        $router->aliasMiddleware('role_or_permission', RoleOrPermissionMiddleware::class);
    }

    private function registerEventSubscriber(): void
    {
        Event::subscribe(CacheInvalidator::class);
    }

    private function registerWarmOnLogin(): void
    {
        Event::listen(Login::class, WarmCacheOnLogin::class);
    }
}
