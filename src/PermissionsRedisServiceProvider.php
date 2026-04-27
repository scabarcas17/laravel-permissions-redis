<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis;

use Closure;
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
use Scabarcas\LaravelPermissionsRedis\Cache\TenantAwareRedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Commands\CacheStatsCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\FlushCacheCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\MigrateFromSpatieCommand;
use Scabarcas\LaravelPermissionsRedis\Commands\SeedCommand;
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
use Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions;

class PermissionsRedisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/permissions-redis.php', 'permissions-redis');

        $this->app->singleton(RedisPermissionRepository::class);

        if (config('permissions-redis.tenancy.enabled', false)) {
            $this->app->singleton(PermissionRepositoryInterface::class, function (): TenantAwareRedisPermissionRepository {
                return new TenantAwareRedisPermissionRepository(
                    $this->app->make(RedisPermissionRepository::class),
                    $this->resolveTenantResolver(),
                );
            });
        } else {
            $this->app->singleton(PermissionRepositoryInterface::class, RedisPermissionRepository::class);
        }

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

        if (config('permissions-redis.octane.reset_on_request', false)) {
            $this->registerOctaneReset();
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
                MigrateFromSpatieCommand::class,
                SeedCommand::class,
            ]);
        }
    }

    private function registerGateIntegration(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            $userModels = $this->configuredUserModels();

            $matches = false;

            foreach ($userModels as $userModel) {
                if ($user instanceof $userModel) {
                    $matches = true;

                    break;
                }
            }

            if (!$matches) {
                return null;
            }

            /** @var PermissionResolverInterface $resolver */
            $resolver = app(PermissionResolverInterface::class);

            /** @var int|string $userId */
            $userId = $user->getAuthIdentifier();

            $guard = $this->resolveGuardForUser($user);

            if ($resolver->hasPermission($userId, $ability, $guard)) {
                return true;
            }

            return null;
        });
    }

    private function resolveGuardForUser(Authenticatable $user): string
    {
        $auth = auth();

        /** @var array<string, array<string, mixed>> $guards */
        $guards = config('auth.guards', []);

        foreach ($guards as $name => $_config) {
            $candidate = $auth->guard($name)->user();

            if ($candidate !== null && $candidate->getAuthIdentifier() === $user->getAuthIdentifier()) {
                return $name;
            }
        }

        return $auth->getDefaultDriver();
    }

    /**
     * @return array<string>
     */
    private function configuredUserModels(): array
    {
        $model = config('permissions-redis.user_model', 'App\\Models\\User');

        if (is_array($model)) {
            /** @var array<string> $model */
            return array_values($model);
        }

        /** @var string $model */
        return [$model];
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

    /**
     * @throws BindingResolutionException
     *
     * @return Closure(): (string|int|null)
     */
    private function resolveTenantResolver(): Closure
    {
        /** @var string|null $resolver */
        $resolver = config('permissions-redis.tenancy.resolver');

        if ($resolver === 'stancl') {
            return static function (): string|int|null {
                $tenancyClass = 'Stancl\\Tenancy\\Tenancy';

                if (!class_exists($tenancyClass) || !app()->bound($tenancyClass)) {
                    return null;
                }

                $tenancy = app($tenancyClass);

                if (!is_object($tenancy) || !method_exists($tenancy, 'getTenant')) {
                    return null;
                }

                $tenant = $tenancy->getTenant();

                if (!is_object($tenant) || !method_exists($tenant, 'getTenantKey')) {
                    return null;
                }

                $key = $tenant->getTenantKey();

                return is_string($key) || is_int($key) ? $key : null;
            };
        }

        if (is_string($resolver) && class_exists($resolver)) {
            /** @var Closure(): (string|int|null) */
            return $this->app->make($resolver);
        }

        return static fn (): null => null;
    }

    private function registerOctaneReset(): void
    {
        $requestReceived = 'Laravel\Octane\Events\RequestReceived';

        if (!class_exists($requestReceived)) {
            return;
        }

        Event::listen($requestReceived, function (): void {
            /** @var PermissionResolverInterface $resolver */
            $resolver = $this->app->make(PermissionResolverInterface::class);
            $resolver->flush();

            /** @var RedisPermissionRepository $repository */
            $repository = $this->app->make(RedisPermissionRepository::class);
            $repository->resetState();

            HasRedisPermissions::flushRoleIdNameCache();
        });
    }
}
