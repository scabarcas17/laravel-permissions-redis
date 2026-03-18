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
        $modelHasPermissions = $tableNames['model_has_permissions'] ?? 'model_has_permissions';

        Schema::create($modelHasPermissions, function (Blueprint $table) use ($permissionsTable) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')
                ->on($permissionsTable)
                ->onDelete('cascade');

            $table->primary(
                ['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });
    }

    public function down(): void
    {
        $tableNames = config('permissions-redis.tables', []);

        Schema::dropIfExists($tableNames['model_has_permissions'] ?? 'model_has_permissions');
    }
};
