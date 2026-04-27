<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;

class SeedCommand extends Command
{
    protected $signature = 'permissions-redis:seed
        {--fresh : Delete all existing permissions and roles before seeding}
        {--no-warm : Skip Redis cache warming after seeding}
        {--guard= : Guard name to use for seeded roles and permissions}';

    protected $description = 'Seed permissions and roles from config';

    private int $permissionsCreated = 0;

    private int $rolesCreated = 0;

    private string $guardName = 'web';

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        /** @var array{roles?: array<string, mixed>, permissions?: array<mixed>, guard?: string} $seedConfig */
        $seedConfig = config('permissions-redis.seed', []);

        /** @var array<string, mixed> $roles */
        $roles = $seedConfig['roles'] ?? [];

        /** @var array<mixed> $standalonePermissions */
        $standalonePermissions = $seedConfig['permissions'] ?? [];

        $guardOption = $this->option('guard');

        if (is_string($guardOption) && $guardOption !== '') {
            $this->guardName = $guardOption;
        } else {
            $this->guardName = $seedConfig['guard'] ?? 'web';
        }

        if ($roles === [] && $standalonePermissions === []) {
            $this->warn('No seed data found in config/permissions-redis.php.');
            $this->line('Define roles and permissions under the \'seed\' key.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            if (!$this->confirmFresh()) {
                return self::SUCCESS;
            }

            $this->freshDelete();
        }

        $this->seedPermissions($standalonePermissions);
        $this->seedRoles($roles);

        $this->newLine();
        $this->info("Seeded {$this->permissionsCreated} permissions and {$this->rolesCreated} roles.");

        if (!$this->option('no-warm')) {
            $this->newLine();
            $this->info('Warming Redis cache...');
            $start = microtime(true);
            $cacheManager->warmAll();
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->info("  Cache warmed in {$elapsed}ms.");
        }

        return self::SUCCESS;
    }

    private function confirmFresh(): bool
    {
        if (app()->environment('production')) {
            return (bool) $this->confirm(
                'You are in PRODUCTION. This will delete ALL permissions and roles. Continue?',
                false
            );
        }

        return true;
    }

    private function freshDelete(): void
    {
        $this->warn('Deleting all existing permissions and roles...');

        /** @var string $modelHasRoles */
        $modelHasRoles = config('permissions-redis.tables.model_has_roles', 'model_has_roles');
        /** @var string $modelHasPermissions */
        $modelHasPermissions = config('permissions-redis.tables.model_has_permissions', 'model_has_permissions');
        /** @var string $roleHasPermissions */
        $roleHasPermissions = config('permissions-redis.tables.role_has_permissions', 'role_has_permissions');

        // Pivots first: FK cascade order.
        DB::table($modelHasRoles)->delete();
        DB::table($modelHasPermissions)->delete();
        DB::table($roleHasPermissions)->delete();

        Role::withoutEvents(fn () => Role::query()->delete());
        Permission::withoutEvents(fn () => Permission::query()->delete());

        /** @var PermissionRepositoryInterface $repository */
        $repository = app(PermissionRepositoryInterface::class);
        $repository->flushAll();

        $this->line('  All existing data deleted.');
    }

    /** @param array<mixed> $permissions */
    private function seedPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (is_string($permission)) {
                $this->findOrCreatePermission($permission, $this->guardName);

                continue;
            }

            if (is_array($permission) && isset($permission['name']) && is_string($permission['name'])) {
                /** @var string $guard */
                $guard = (isset($permission['guard']) && is_string($permission['guard']))
                    ? $permission['guard']
                    : $this->guardName;

                $this->findOrCreatePermission($permission['name'], $guard);
            }
        }
    }

    /** @param array<string, mixed> $roles */
    private function seedRoles(array $roles): void
    {
        foreach ($roles as $roleName => $roleConfig) {
            [$guard, $permissions] = $this->normalizeRoleEntry($roleConfig);

            $role = Role::query()->firstOrCreate(
                ['name' => $roleName, 'guard_name' => $guard],
            );

            $wasRecentlyCreated = $role->wasRecentlyCreated;

            if ($wasRecentlyCreated) {
                $this->rolesCreated++;
                $this->line("  Created role: <info>{$roleName}</info> (guard: {$guard})");
            } else {
                $this->line("  Role exists: {$roleName}");
            }

            $permissionIds = [];

            foreach ($permissions as $permission) {
                $permModel = $this->findOrCreatePermission($permission, $guard);
                $permissionIds[] = $permModel->id;
            }

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function normalizeRoleEntry(mixed $roleConfig): array
    {
        if (is_array($roleConfig) && array_is_list($roleConfig)) {
            /** @var array<string> $roleConfig */
            return [$this->guardName, $roleConfig];
        }

        if (is_array($roleConfig)) {
            /** @var string $guard */
            $guard = (isset($roleConfig['guard']) && is_string($roleConfig['guard']))
                ? $roleConfig['guard']
                : $this->guardName;

            /** @var array<string> $permissions */
            $permissions = (isset($roleConfig['permissions']) && is_array($roleConfig['permissions']))
                ? array_values($roleConfig['permissions'])
                : [];

            return [$guard, $permissions];
        }

        return [$this->guardName, []];
    }

    private function findOrCreatePermission(string $name, string $guard): Permission
    {
        $permission = Permission::findOrCreate($name, $guard);

        if ($permission->wasRecentlyCreated) {
            $this->permissionsCreated++;
            $this->line("  Created permission: <info>{$name}</info> (guard: {$guard})");
        }

        return $permission;
    }
}
