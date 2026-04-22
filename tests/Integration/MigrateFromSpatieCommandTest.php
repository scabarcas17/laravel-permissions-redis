<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scabarcas\LaravelPermissionsRedis\Cache\AuthorizationCacheManager;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

beforeEach(function () {
    $this->repo = new InMemoryPermissionRepository();
    $this->app->instance(PermissionRepositoryInterface::class, $this->repo);
    $this->app->singleton(AuthorizationCacheManager::class, function () {
        return new AuthorizationCacheManager($this->repo);
    });
});

test('migrate command detects same tables and ensures schema compatibility', function () {
    // Spatie uses the same default table names as our package
    config()->set('permission.table_names', [
        'permissions'           => 'permissions',
        'roles'                 => 'roles',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ]);

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    DB::table('permissions')->insert(['name' => 'users.create', 'guard_name' => 'web']);
    $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:migrate-from-spatie --no-warm')
        ->expectsOutputToContain('Tables are the same')
        ->expectsOutputToContain('Summary:')
        ->assertSuccessful();
});

test('migrate command fails when spatie tables do not exist', function () {
    config()->set('permission.table_names', [
        'permissions'           => 'spatie_permissions',
        'roles'                 => 'spatie_roles',
        'model_has_permissions' => 'spatie_model_has_permissions',
        'model_has_roles'       => 'spatie_model_has_roles',
        'role_has_permissions'  => 'spatie_role_has_permissions',
    ]);

    $this->artisan('permissions-redis:migrate-from-spatie')
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

test('migrate command dry run makes no changes', function () {
    config()->set('permission.table_names', [
        'permissions'           => 'permissions',
        'roles'                 => 'roles',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ]);

    DB::table('permissions')->insert(['name' => 'users.create', 'guard_name' => 'web']);

    $this->artisan('permissions-redis:migrate-from-spatie --dry-run')
        ->expectsOutputToContain('Ensuring schema compatibility')
        ->assertSuccessful();
});

test('migrate command warms cache after migration', function () {
    config()->set('permission.table_names', [
        'permissions'           => 'permissions',
        'roles'                 => 'roles',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ]);

    $user = User::create(['name' => 'Jane', 'email' => 'jane@test.com']);
    $permId = DB::table('permissions')->insertGetId(['name' => 'posts.edit', 'guard_name' => 'web']);
    DB::table('model_has_permissions')->insert([
        'permission_id' => $permId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:migrate-from-spatie')
        ->expectsOutputToContain('Warming Redis cache')
        ->expectsOutputToContain('Migration complete')
        ->assertSuccessful();

    expect($this->repo->getUserPermissions($user->id))->toContain('web|posts.edit');
});

test('migrate command fails when target tables do not exist and tables differ', function () {
    // Create spatie tables but configure target tables that don't exist
    Schema::create('sp_permissions', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('sp_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('sp_model_has_permissions', function ($table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    Schema::create('sp_model_has_roles', function ($table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    Schema::create('sp_role_has_permissions', function ($table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
    });

    config()->set('permission.table_names', [
        'permissions'           => 'sp_permissions',
        'roles'                 => 'sp_roles',
        'model_has_permissions' => 'sp_model_has_permissions',
        'model_has_roles'       => 'sp_model_has_roles',
        'role_has_permissions'  => 'sp_role_has_permissions',
    ]);

    // Point target to non-existent tables
    config()->set('permissions-redis.tables', [
        'permissions'           => 'nonexistent_permissions',
        'roles'                 => 'nonexistent_roles',
        'model_has_permissions' => 'nonexistent_mhp',
        'model_has_roles'       => 'nonexistent_mhr',
        'role_has_permissions'  => 'nonexistent_rhp',
    ]);

    $this->artisan('permissions-redis:migrate-from-spatie --no-warm')
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

test('migrate command copies data when tables differ', function () {
    // Create separate "spatie" tables
    Schema::create('sp_permissions', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->string('description')->nullable();
        $table->string('group')->nullable();
        $table->timestamps();
    });
    Schema::create('sp_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->string('description')->nullable();
        $table->timestamps();
    });
    Schema::create('sp_model_has_permissions', function ($table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    Schema::create('sp_model_has_roles', function ($table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    Schema::create('sp_role_has_permissions', function ($table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
    });

    config()->set('permission.table_names', [
        'permissions'           => 'sp_permissions',
        'roles'                 => 'sp_roles',
        'model_has_permissions' => 'sp_model_has_permissions',
        'model_has_roles'       => 'sp_model_has_roles',
        'role_has_permissions'  => 'sp_role_has_permissions',
    ]);

    $user = User::create(['name' => 'John', 'email' => 'john@test.com']);

    // Seed spatie tables
    $permId = DB::table('sp_permissions')->insertGetId([
        'name' => 'users.create', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('sp_roles')->insertGetId([
        'name' => 'admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sp_role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    DB::table('sp_model_has_roles')->insert([
        'role_id' => $roleId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);
    DB::table('sp_model_has_permissions')->insert([
        'permission_id' => $permId, 'model_id' => $user->id, 'model_type' => User::class,
    ]);

    $this->artisan('permissions-redis:migrate-from-spatie --no-warm')
        ->expectsOutputToContain('Tables differ')
        ->expectsOutputToContain('Copying')
        ->expectsOutputToContain('Summary: 1 permissions, 1 roles')
        ->assertSuccessful();

    // Verify data was copied to target tables
    $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
    $this->assertDatabaseHas('roles', ['name' => 'admin']);
});
