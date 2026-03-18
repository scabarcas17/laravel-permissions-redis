<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tableNames = config('permissions-redis.tables', []);
        $permissionsTable = $tableNames['permissions'] ?? 'permissions';
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $roleHasPermissions = $tableNames['role_has_permissions'] ?? 'role_has_permissions';

        Schema::create($roleHasPermissions, function (Blueprint $table) use ($permissionsTable, $rolesTable) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')
                ->on($permissionsTable)
                ->onDelete('cascade');

            $table->foreign('role_id')
                ->references('id')
                ->on($rolesTable)
                ->onDelete('cascade');

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    public function down(): void
    {
        $tableNames = config('permissions-redis.tables', []);

        Schema::dropIfExists($tableNames['role_has_permissions'] ?? 'role_has_permissions');
    }
};
