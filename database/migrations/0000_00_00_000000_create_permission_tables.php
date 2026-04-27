<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tables = config('permissions-redis.tables', []);
        $morphKeyType = config('permissions-redis.model_morph_key_type', 'int');

        // Lengths of 191 keep (name, guard_name) unique indexes within
        // MySQL's 767-byte key limit under utf8mb4 on MySQL < 5.7.7.
        Schema::create($tables['permissions'] ?? 'permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('description')->nullable();
            $table->string('group', 191)->nullable();
            $table->string('guard_name', 191);
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
            $table->index('group');
        });

        Schema::create($tables['roles'] ?? 'roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('description')->nullable();
            $table->string('guard_name', 191);
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        // 3. model_has_permissions pivot
        $permissionsTable = $tables['permissions'] ?? 'permissions';
        $modelHasPermissions = $tables['model_has_permissions'] ?? 'model_has_permissions';

        Schema::create($modelHasPermissions, function (Blueprint $table) use ($permissionsTable, $morphKeyType) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $this->addMorphKeyColumn($table, $morphKeyType);
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on($permissionsTable)->onDelete('cascade');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        // 4. role_has_permissions pivot
        $rolesTable = $tables['roles'] ?? 'roles';
        $roleHasPermissions = $tables['role_has_permissions'] ?? 'role_has_permissions';

        Schema::create($roleHasPermissions, function (Blueprint $table) use ($permissionsTable, $rolesTable) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on($permissionsTable)->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on($rolesTable)->onDelete('cascade');
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // 5. model_has_roles pivot
        $modelHasRoles = $tables['model_has_roles'] ?? 'model_has_roles';

        Schema::create($modelHasRoles, function (Blueprint $table) use ($rolesTable, $morphKeyType) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $this->addMorphKeyColumn($table, $morphKeyType);
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on($rolesTable)->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });
    }

    public function down(): void
    {
        $tables = config('permissions-redis.tables', []);

        Schema::dropIfExists($tables['model_has_roles'] ?? 'model_has_roles');
        Schema::dropIfExists($tables['role_has_permissions'] ?? 'role_has_permissions');
        Schema::dropIfExists($tables['model_has_permissions'] ?? 'model_has_permissions');
        Schema::dropIfExists($tables['roles'] ?? 'roles');
        Schema::dropIfExists($tables['permissions'] ?? 'permissions');
    }

    private function addMorphKeyColumn(Blueprint $table, string $type): void
    {
        match ($type) {
            'uuid'  => $table->uuid('model_id'),
            'ulid'  => $table->ulid('model_id'),
            default => $table->unsignedBigInteger('model_id'),
        };
    }
};
