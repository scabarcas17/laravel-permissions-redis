<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Models\Permission;
use Scabarcas\LaravelPermissionsRedis\Models\Role;

class SeedCommand extends Command
{
    protected $signature = 'permissions-redis:seed
        {--fresh : Delete all existing permissions and roles before seeding}
        {--no-warm : Skip Redis cache warming after seeding}';

    protected $description = 'Seed permissions and roles from config';

    private int $permissionsCreated = 0;

    private int $rolesCreated = 0;

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        /** @var array{roles?: array<string, array<string>>, permissions?: array<string>} $seedConfig */
        $seedConfig = config('permissions-redis.seed', []);

        /** @var array<string, array<string>> $roles */
        $roles = $seedConfig['roles'] ?? [];

        /** @var array<string> $standalonePermissions */
        $standalonePermissions = $seedConfig['permissions'] ?? [];

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

        /** @var string $rolesTable */
        $rolesTable = config('permissions-redis.tables.model_has_roles', 'model_has_roles');
        /** @var string $permissionsTable */
        $permissionsTable = config('permissions-redis.tables.model_has_permissions', 'model_has_permissions');
        /** @var string $rolePermissionsTable */
        $rolePermissionsTable = config('permissions-redis.tables.role_has_permissions', 'role_has_permissions');

        // Delete pivots first to avoid FK violations
        \Illuminate\Support\Facades\DB::table($rolesTable)->delete();
        \Illuminate\Support\Facades\DB::table($permissionsTable)->delete();
        \Illuminate\Support\Facades\DB::table($rolePermissionsTable)->delete();

        Role::query()->delete();
        Permission::query()->delete();

        $this->line('  All existing data deleted.');
    }

    /** @param array<string> $permissions */
    private function seedPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->findOrCreatePermission($permission);
        }
    }

    /** @param array<string, array<string>> $roles */
    private function seedRoles(array $roles): void
    {
        foreach ($roles as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
            );

            $wasRecentlyCreated = $role->wasRecentlyCreated;

            if ($wasRecentlyCreated) {
                $this->rolesCreated++;
                $this->line("  Created role: <info>{$roleName}</info>");
            } else {
                $this->line("  Role exists: {$roleName}");
            }

            $permissionIds = [];

            foreach ($permissions as $permission) {
                $permModel = $this->findOrCreatePermission($permission);
                $permissionIds[] = $permModel->id;
            }

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    private function findOrCreatePermission(string $name): Permission
    {
        $permission = Permission::findOrCreate($name);

        if ($permission->wasRecentlyCreated) {
            $this->permissionsCreated++;
            $this->line("  Created permission: <info>{$name}</info>");
        }

        return $permission;
    }
}
