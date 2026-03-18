<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $tableNames = config('permissions-redis.tables', []);
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $modelHasRoles = $tableNames['model_has_roles'] ?? 'model_has_roles';

        Schema::create($modelHasRoles, function (Blueprint $table) use ($rolesTable) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')
                ->on($rolesTable)
                ->onDelete('cascade');

            $table->primary(
                ['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });
    }

    public function down(): void
    {
        $tableNames = config('permissions-redis.tables', []);

        Schema::dropIfExists($tableNames['model_has_roles'] ?? 'model_has_roles');
    }
};
