<?php

declare(strict_types=1);

namespace Sebastian\LaravelPermissionsRedis;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Sebastian\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Sebastian\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Sebastian\LaravelPermissionsRedis\Commands\FlushCacheCommand;
use Sebastian\LaravelPermissionsRedis\Commands\WarmCacheCommand;
use Sebastian\LaravelPermissionsRedis\Commands\WarmUserCacheCommand;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Sebastian\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Sebastian\LaravelPermissionsRedis\Listeners\CacheInvalidator;
use Sebastian\LaravelPermissionsRedis\Listeners\WarmCacheOnLogin;
use Sebastian\LaravelPermissionsRedis\Middleware\PermissionMiddleware;
use Sebastian\LaravelPermissionsRedis\Middleware\RoleMiddleware;
use Sebastian\LaravelPermissionsRedis\Middleware\RoleOrPermissionMiddleware;
use Sebastian\LaravelPermissionsRedis\Resolver\PermissionResolver;

class PermissionsRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permissions-redis.php', 'permissions-redis');

        $this->app->singleton(PermissionRepositoryInterface::class, RedisPermissionRepository::class);
        $this->app->singleton(RedisPermissionRepository::class);

        $this->app->singleton(AuthorizationCacheManager::class);

        $this->app->singleton(PermissionResolverInterface::class, PermissionResolver::class);
        $this->app->singleton(PermissionResolver::class);
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

        if (config('permissions-redis.warm_on_login', true)) {
            $this->registerWarmOnLogin();
        }
    }

    private function publishAssets(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/permissions-redis.php' => config_path('permissions-redis.php'),
            ], 'permissions-redis-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'permissions-redis-migrations');
        }
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WarmCacheCommand::class,
                WarmUserCacheCommand::class,
                FlushCacheCommand::class,
            ]);
        }
    }

    private function registerGateIntegration(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            /** @var string $userModel */
            $userModel = config('permissions-redis.user_model', 'App\\Models\\User');

            if (!$user instanceof $userModel) {
                return null;
            }

            /** @var PermissionResolver $resolver */
            $resolver = app(PermissionResolver::class);

            if ($resolver->hasPermission($user->id, $ability)) {
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
