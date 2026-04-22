<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;

class MigrateFromSpatieCommand extends Command
{
    protected $signature = 'permissions-redis:migrate-from-spatie
        {--dry-run : Show what would be migrated without making changes}
        {--no-warm : Skip Redis cache warming after migration}';

    protected $description = 'Migrate data from spatie/laravel-permission to this package';

    /** @var array<string, string> */
    private array $spatieTableNames = [];

    /** @var array<string, string> */
    private array $targetTableNames = [];

    private bool $dryRun = false;

    public function handle(AuthorizationCacheManager $cacheManager): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
            $this->newLine();
        }

        $this->info('Detecting Spatie configuration...');

        $this->spatieTableNames = $this->resolveSpatieTableNames();
        $this->targetTableNames = $this->resolveTargetTableNames();

        $this->table(['Table', 'Spatie', 'Target'], [
            ['permissions', $this->spatieTableNames['permissions'], $this->targetTableNames['permissions']],
            ['roles', $this->spatieTableNames['roles'], $this->targetTableNames['roles']],
            ['model_has_permissions', $this->spatieTableNames['model_has_permissions'], $this->targetTableNames['model_has_permissions']],
            ['model_has_roles', $this->spatieTableNames['model_has_roles'], $this->targetTableNames['model_has_roles']],
            ['role_has_permissions', $this->spatieTableNames['role_has_permissions'], $this->targetTableNames['role_has_permissions']],
        ]);

        $this->newLine();

        if (!$this->validateSpatieTables()) {
            return self::FAILURE;
        }

        $tablesAreSame = $this->tablesAreSame();

        if ($tablesAreSame) {
            $this->info('Tables are the same — reusing existing data.');
            $this->ensureSchemaCompatibility();
        } else {
            $this->info('Tables differ — copying data from Spatie tables...');

            if (!$this->ensureTargetTablesExist()) {
                return self::FAILURE;
            }

            $this->copyData();
        }

        $this->newLine();
        $this->printSummary();

        if (!$this->dryRun && !$this->option('no-warm')) {
            $this->newLine();
            $this->info('Warming Redis cache...');
            $start = microtime(true);
            $cacheManager->warmAll();
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->info("  Authorization cache warmed successfully in {$elapsed}ms.");
        }

        $this->newLine();

        if ($this->dryRun) {
            $this->warn('DRY RUN complete. No changes were made.');
        } else {
            $this->info('Migration complete!');
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function resolveSpatieTableNames(): array
    {
        /** @var array<string, string> $spatieConfig */
        $spatieConfig = config('permission.table_names', []);

        return [
            'permissions'           => $spatieConfig['permissions'] ?? 'permissions',
            'roles'                 => $spatieConfig['roles'] ?? 'roles',
            'model_has_permissions' => $spatieConfig['model_has_permissions'] ?? 'model_has_permissions',
            'model_has_roles'       => $spatieConfig['model_has_roles'] ?? 'model_has_roles',
            'role_has_permissions'  => $spatieConfig['role_has_permissions'] ?? 'role_has_permissions',
        ];
    }

    /** @return array<string, string> */
    private function resolveTargetTableNames(): array
    {
        return [
            'permissions'           => $this->targetTable('permissions'),
            'roles'                 => $this->targetTable('roles'),
            'model_has_permissions' => $this->targetTable('model_has_permissions'),
            'model_has_roles'       => $this->targetTable('model_has_roles'),
            'role_has_permissions'  => $this->targetTable('role_has_permissions'),
        ];
    }

    private function targetTable(string $key): string
    {
        /** @var string $table */
        $table = config("permissions-redis.tables.{$key}", $key);

        return $table;
    }

    private function validateSpatieTables(): bool
    {
        foreach ($this->spatieTableNames as $key => $tableName) {
            if (!Schema::hasTable($tableName)) {
                $this->error("Spatie table '{$tableName}' ({$key}) does not exist.");
                $this->error('Ensure spatie/laravel-permission migrations have been run.');

                return false;
            }
        }

        return true;
    }

    private function tablesAreSame(): bool
    {
        foreach ($this->spatieTableNames as $key => $spatieName) {
            if ($spatieName !== $this->targetTableNames[$key]) {
                return false;
            }
        }

        return true;
    }

    private function ensureSchemaCompatibility(): void
    {
        $this->info('Ensuring schema compatibility...');

        $permissionsTable = $this->targetTableNames['permissions'];
        $rolesTable = $this->targetTableNames['roles'];

        if (!Schema::hasColumn($permissionsTable, 'description')) {
            $this->line("  Adding 'description' column to {$permissionsTable} table.");

            if (!$this->dryRun) {
                Schema::table($permissionsTable, function (Blueprint $table): void {
                    $table->string('description')->nullable()->after('name');
                });
            }
        }

        if (!Schema::hasColumn($permissionsTable, 'group')) {
            $this->line("  Adding 'group' column to {$permissionsTable} table.");

            if (!$this->dryRun) {
                Schema::table($permissionsTable, function (Blueprint $table): void {
                    $table->string('group')->nullable()->after('description');
                });
            }
        }

        if (!Schema::hasColumn($rolesTable, 'description')) {
            $this->line("  Adding 'description' column to {$rolesTable} table.");

            if (!$this->dryRun) {
                Schema::table($rolesTable, function (Blueprint $table): void {
                    $table->string('description')->nullable()->after('name');
                });
            }
        }
    }

    private function ensureTargetTablesExist(): bool
    {
        foreach ($this->targetTableNames as $key => $tableName) {
            if (!Schema::hasTable($tableName)) {
                $this->error("Target table '{$tableName}' ({$key}) does not exist.");
                $this->error("Run 'php artisan migrate' first to create the target tables.");

                return false;
            }
        }

        return true;
    }

    private function copyData(): void
    {
        $this->copyPermissions();
        $this->copyRoles();
        $this->copyPivot('role_has_permissions', ['permission_id', 'role_id']);
        $this->copyPivot('model_has_permissions', ['permission_id', 'model_type', 'model_id']);
        $this->copyPivot('model_has_roles', ['role_id', 'model_type', 'model_id']);
    }

    private function copyPermissions(): void
    {
        $source = $this->spatieTableNames['permissions'];
        $target = $this->targetTableNames['permissions'];
        $count = DB::table($source)->count();

        $this->line("  Copying {$count} permissions from '{$source}' to '{$target}'...");

        if ($this->dryRun || $count === 0) {
            return;
        }

        $hasDescription = Schema::hasColumn($source, 'description');
        $hasGroup = Schema::hasColumn($source, 'group');

        DB::table($source)->orderBy('id')->chunk(500, function ($rows) use ($target, $hasDescription, $hasGroup) {
            $inserts = [];

            foreach ($rows as $row) {
                $insert = [
                    'id'         => $row->id,
                    'name'       => $row->name,
                    'guard_name' => $row->guard_name,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];

                $insert['description'] = $hasDescription ? $row->description : null;
                $insert['group'] = $hasGroup ? $row->group : null;

                $inserts[] = $insert;
            }

            DB::table($target)->insert($inserts);
        });
    }

    private function copyRoles(): void
    {
        $source = $this->spatieTableNames['roles'];
        $target = $this->targetTableNames['roles'];
        $count = DB::table($source)->count();

        $this->line("  Copying {$count} roles from '{$source}' to '{$target}'...");

        if ($this->dryRun || $count === 0) {
            return;
        }

        $hasDescription = Schema::hasColumn($source, 'description');

        DB::table($source)->orderBy('id')->chunk(500, function ($rows) use ($target, $hasDescription) {
            $inserts = [];

            foreach ($rows as $row) {
                $insert = [
                    'id'         => $row->id,
                    'name'       => $row->name,
                    'guard_name' => $row->guard_name,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];

                $insert['description'] = $hasDescription ? $row->description : null;

                $inserts[] = $insert;
            }

            DB::table($target)->insert($inserts);
        });
    }

    /**
     * @param array<string> $columns
     */
    private function copyPivot(string $key, array $columns): void
    {
        $source = $this->spatieTableNames[$key];
        $target = $this->targetTableNames[$key];
        $count = DB::table($source)->count();

        $this->line("  Copying {$count} rows from '{$source}' to '{$target}'...");

        if ($this->dryRun || $count === 0) {
            return;
        }

        DB::table($source)->select($columns)->orderBy($columns[0])->chunk(500, function ($rows) use ($target, $columns) {
            $inserts = [];

            foreach ($rows as $row) {
                $insert = [];

                foreach ($columns as $col) {
                    $insert[$col] = $row->{$col};
                }

                $inserts[] = $insert;
            }

            DB::table($target)->insert($inserts);
        });
    }

    private function printSummary(): void
    {
        $permissionsCount = DB::table($this->targetTableNames['permissions'])->count();
        $rolesCount = DB::table($this->targetTableNames['roles'])->count();

        $userIds = DB::table($this->targetTableNames['model_has_roles'])
            ->select('model_id')
            ->union(
                DB::table($this->targetTableNames['model_has_permissions'])->select('model_id')
            )
            ->distinct()
            ->count();

        $this->info("Summary: {$permissionsCount} permissions, {$rolesCount} roles, {$userIds} users with assignments.");
    }
}
