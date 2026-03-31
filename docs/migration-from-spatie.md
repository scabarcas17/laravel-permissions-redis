# Migrating from spatie/laravel-permission

This guide walks you through replacing `spatie/laravel-permission` with `scabarcas/laravel-permissions-redis`. The API is intentionally similar, so most changes are namespace swaps and config adjustments.

---

## Table of Contents

- [Overview](#overview)
- [Step 1 — Install the package](#step-1--install-the-package)
- [Step 2 — Publish config and migrations](#step-2--publish-config-and-migrations)
- [Step 3 — Run the migration command](#step-3--run-the-migration-command)
- [Step 4 — Update the User model](#step-4--update-the-user-model)
- [Step 5 — Update imports](#step-5--update-imports)
- [Step 6 — Update config references](#step-6--update-config-references)
- [Step 7 — Update Blade directives](#step-7--update-blade-directives)
- [Step 8 — Update middleware references](#step-8--update-middleware-references)
- [Step 9 — Remove Spatie](#step-9--remove-spatie)
- [Step 10 — Warm the cache](#step-10--warm-the-cache)
- [Method equivalence table](#method-equivalence-table)
- [Behavior differences](#behavior-differences)
- [Config mapping](#config-mapping)
- [FAQ](#faq)

---

## Overview

Both packages share the same database schema (5 tables) and a very similar API. The main differences are:

| Aspect | spatie/laravel-permission | laravel-permissions-redis |
|--------|---------------------------|---------------------------|
| **Cache backend** | Laravel Cache (file/db/Redis via Cache facade) | Redis directly (SET data structures) |
| **Cache strategy** | Forget on change, lazy reload | Warm on change, always hot |
| **Trait name** | `HasRoles` | `HasRedisPermissions` |
| **Config file** | `config/permission.php` | `config/permissions-redis.php` |
| **Namespace** | `Spatie\Permission\*` | `Scabarcas\LaravelPermissionsRedis\*` |
| **Extra features** | Teams, direct/via-role separation | Wildcard permissions, super admin, Octane, multi-tenancy |

---

## Step 1 — Install the package

Install alongside Spatie first (both can coexist temporarily):

```bash
composer require scabarcas/laravel-permissions-redis
```

---

## Step 2 — Publish config and migrations

```bash
php artisan vendor:publish --provider="Scabarcas\LaravelPermissionsRedis\PermissionsRedisServiceProvider"
```

This creates `config/permissions-redis.php` and the migration files.

**Important:** If you are using the **same table names** as Spatie (the defaults: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`), do **not** run `php artisan migrate` yet — the tables already exist. The migration command in Step 3 handles this for you.

If you want **different table names**, update `config/permissions-redis.php` first:

```php
'tables' => [
    'permissions'           => 'redis_permissions',
    'roles'                 => 'redis_roles',
    'model_has_permissions' => 'redis_model_has_permissions',
    'model_has_roles'       => 'redis_model_has_roles',
    'role_has_permissions'  => 'redis_role_has_permissions',
],
```

Then run `php artisan migrate` to create the new tables.

---

## Step 3 — Run the migration command

```bash
php artisan permissions-redis:migrate-from-spatie
```

This command:

1. Detects Spatie's table names from `config/permission.php` (or uses defaults)
2. Detects our table names from `config/permissions-redis.php`
3. If tables differ, copies all data (permissions, roles, pivots) to the new tables
4. If tables are the same, adds the `description` and `group` columns if missing
5. Warms the full Redis cache

**Options:**

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be migrated without making changes |
| `--no-warm` | Skip Redis cache warming (you can run `permissions-redis:warm` later) |

**Example output:**

```
Detecting Spatie configuration...
  Spatie tables: permissions, roles, model_has_permissions, model_has_roles, role_has_permissions
  Target tables: permissions, roles, model_has_permissions, model_has_roles, role_has_permissions
  Tables are the same — reusing existing data.

Ensuring schema compatibility...
  Added 'description' column to permissions table.
  Added 'group' column to permissions table.
  Added 'description' column to roles table.

Warming Redis cache...
  Authorization cache warmed successfully in 42ms.

Migration complete! 12 permissions, 4 roles, 3 users migrated.
```

---

## Step 4 — Update the User model

Replace the Spatie trait with the Redis-backed trait:

```diff
  namespace App\Models;

  use Illuminate\Foundation\Auth\User as Authenticatable;
- use Spatie\Permission\Traits\HasRoles;
+ use Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions;

  class User extends Authenticatable
  {
-     use HasRoles;
+     use HasRedisPermissions;
  }
```

---

## Step 5 — Update imports

Replace Spatie namespaces throughout your codebase:

| Spatie | This package |
|--------|-------------|
| `Spatie\Permission\Models\Permission` | `Scabarcas\LaravelPermissionsRedis\Models\Permission` |
| `Spatie\Permission\Models\Role` | `Scabarcas\LaravelPermissionsRedis\Models\Role` |
| `Spatie\Permission\Traits\HasRoles` | `Scabarcas\LaravelPermissionsRedis\Traits\HasRedisPermissions` |
| `Spatie\Permission\Exceptions\UnauthorizedException` | `Scabarcas\LaravelPermissionsRedis\Exceptions\UnauthorizedException` |
| `Spatie\Permission\Middleware\*` | `Scabarcas\LaravelPermissionsRedis\Middleware\*` |

**Quick find-and-replace:**

```bash
# Models
grep -rl "Spatie\\\\Permission\\\\Models\\\\Permission" app/ --include="*.php" | xargs sed -i '' 's/Spatie\\Permission\\Models\\Permission/Scabarcas\\LaravelPermissionsRedis\\Models\\Permission/g'

grep -rl "Spatie\\\\Permission\\\\Models\\\\Role" app/ --include="*.php" | xargs sed -i '' 's/Spatie\\Permission\\Models\\Role/Scabarcas\\LaravelPermissionsRedis\\Models\\Role/g'

# Trait
grep -rl "Spatie\\\\Permission\\\\Traits\\\\HasRoles" app/ --include="*.php" | xargs sed -i '' 's/Spatie\\Permission\\Traits\\HasRoles/Scabarcas\\LaravelPermissionsRedis\\Traits\\HasRedisPermissions/g'

grep -rl "use HasRoles" app/ --include="*.php" | xargs sed -i '' 's/use HasRoles;/use HasRedisPermissions;/g'

# Exception
grep -rl "Spatie\\\\Permission\\\\Exceptions" app/ --include="*.php" | xargs sed -i '' 's/Spatie\\Permission\\Exceptions/Scabarcas\\LaravelPermissionsRedis\\Exceptions/g'
```

---

## Step 6 — Update config references

If your code references Spatie config values, update them:

| Spatie config | This package config |
|---------------|---------------------|
| `config('permission.table_names.permissions')` | `config('permissions-redis.tables.permissions')` |
| `config('permission.table_names.roles')` | `config('permissions-redis.tables.roles')` |
| `config('permission.models.permission')` | Not needed (models are not configurable) |
| `config('permission.models.role')` | Not needed |
| `config('permission.cache.expiration_time')` | `config('permissions-redis.ttl')` |
| `config('permission.teams')` | Not applicable (use multi-tenancy instead) |

---

## Step 7 — Update Blade directives

Most Blade directives work identically. Key differences:

| Spatie | This package | Notes |
|--------|-------------|-------|
| `@role('admin')` | `@role('admin')` | Identical |
| `@hasrole('admin')` | `@role('admin')` | Use `@role` instead of `@hasrole` |
| `@hasanyrole('a\|b')` | `@hasanyrole('a\|b')` | Identical |
| `@hasallroles('a\|b')` | `@hasallroles('a\|b')` | Identical |
| `@unlessrole('admin')` | `@role('admin') @else` | Use `@else` block instead |
| `@can('perm')` | `@can('perm')` | Identical (Gate integration) |
| — | `@permission('perm')` | **New:** direct permission check |
| — | `@hasanypermission('a\|b')` | **New:** any permission check |
| — | `@hasallpermissions('a\|b')` | **New:** all permissions check |

**Search for `@hasrole` and `@unlessrole`** in your Blade templates — these are the only directives that need changes:

```bash
grep -rn "@hasrole\|@endhasrole\|@unlessrole\|@endunlessrole" resources/views/
```

Replace `@hasrole` with `@role` and convert `@unlessrole` patterns:

```diff
- @hasrole('admin')
+ @role('admin')
    Admin content
- @endhasrole
+ @endrole

- @unlessrole('admin')
-     Non-admin content
- @endunlessrole
+ @role('admin')
+ @else
+     Non-admin content
+ @endrole
```

---

## Step 8 — Update middleware references

The middleware aliases are the same (`permission`, `role`, `role_or_permission`), so **route files require no changes** if you use string aliases:

```php
// These work identically in both packages
Route::middleware('role:admin')->group(/* ... */);
Route::middleware('permission:posts.edit')->group(/* ... */);
Route::middleware('role_or_permission:admin|posts.view')->group(/* ... */);
```

If you reference Spatie middleware classes directly in `bootstrap/app.php` or a service provider, update them:

```diff
- use Spatie\Permission\Middleware\RoleMiddleware;
+ use Scabarcas\LaravelPermissionsRedis\Middleware\RoleMiddleware;
```

> **Note:** This package registers middleware aliases automatically when `register_middleware` is `true` (default). You can remove any manual middleware registration from your `bootstrap/app.php`.

---

## Step 9 — Remove Spatie

Once everything is working:

```bash
composer remove spatie/laravel-permission
```

Optionally remove Spatie's config file:

```bash
rm config/permission.php
```

---

## Step 10 — Warm the cache

If you didn't use `--no-warm` in Step 3, the cache is already warm. Otherwise:

```bash
php artisan permissions-redis:warm
```

Verify with:

```bash
php artisan permissions-redis:stats
```

---

## Method equivalence table

### User model methods

| Spatie (`HasRoles`) | This package (`HasRedisPermissions`) | Notes |
|---------------------|--------------------------------------|-------|
| `assignRole(...)` | `assignRole(...)` | Identical |
| `syncRoles(...)` | `syncRoles(...)` | Identical |
| `removeRole(...)` | `removeRole(...)` | Identical |
| `hasRole(...)` | `hasRole(...)` | Identical |
| `hasAnyRole(...)` | `hasAnyRole(...)` | Identical |
| `hasAllRoles(...)` | `hasAllRoles(...)` | Identical |
| `getRoleNames()` | `getRoleNames()` | Identical |
| `givePermissionTo(...)` | `givePermissionTo(...)` | Identical |
| `revokePermissionTo(...)` | `revokePermissionTo(...)` | Identical |
| `syncPermissions(...)` | `syncPermissions(...)` | Identical |
| `hasPermissionTo(...)` | `hasPermissionTo(...)` | Identical |
| `hasAnyPermission(...)` | `hasAnyPermission(...)` | Identical |
| `hasAllPermissions(...)` | `hasAllPermissions(...)` | Identical |
| `getAllPermissions()` | `getAllPermissions()` | Returns `Collection<PermissionDTO>` instead of Eloquent Collection |
| `getPermissionNames()` | `getPermissionNames()` | Identical |
| `getDirectPermissions()` | — | Not available (all permissions are merged) |
| `getPermissionsViaRoles()` | — | Not available |
| `hasDirectPermission(...)` | — | Not available |
| `hasPermissionViaRole(...)` | — | Not available |
| `roles()` | `roles()` | Identical (BelongsToMany) |
| `permissions()` | `permissions()` | Identical (BelongsToMany) |
| `scopeRole(...)` | `scopeRole(...)` | Identical |
| `scopePermission(...)` | `scopePermission(...)` | Identical |
| — | `forGuard('api')` | **New:** fluent guard scoping |

### Role model methods

| Spatie | This package | Notes |
|--------|-------------|-------|
| `Role::findOrCreate(name, guard)` | `Role::findOrCreate(name, guard)` | Identical |
| `Permission::findOrCreate(name, guard)` | `Permission::findOrCreate(name, guard, group)` | Optional `group` parameter added |
| `$role->syncPermissions(...)` | `$role->syncPermissions(...)` | Identical |
| `$role->givePermissionTo(...)` | `$role->givePermissionTo(...)` | Identical |
| `$role->revokePermissionTo(...)` | `$role->revokePermissionTo(...)` | Identical |

### Artisan commands

| Spatie | This package | Notes |
|--------|-------------|-------|
| `permission:cache-reset` | `permissions-redis:flush` | Flush cache |
| `permission:create-role` | — | Use `Role::findOrCreate()` |
| `permission:create-permission` | — | Use `Permission::findOrCreate()` |
| `permission:show` | `permissions-redis:stats` | Cache statistics |
| — | `permissions-redis:warm` | **New:** warm full cache |
| — | `permissions-redis:warm-user {id}` | **New:** warm single user |

---

## Behavior differences

### 1. Cache strategy

**Spatie:** Forgets the entire permission cache when any change happens. Next request triggers a full reload from database.

**This package:** Surgically warms only the affected user/role caches in Redis immediately after a change. No cold-start penalty on the next request.

### 2. Direct vs. role-based permission separation

**Spatie** provides methods to distinguish between direct permissions and role-inherited permissions:
- `getDirectPermissions()` — permissions assigned directly to the user
- `getPermissionsViaRoles()` — permissions inherited through roles
- `hasDirectPermission()` — check only direct permissions

**This package** merges all permissions (direct + role-based) into a single Redis SET per user. There is no runtime distinction. If you rely on `getDirectPermissions()` or `hasDirectPermission()`, you will need to refactor that logic.

**Workaround:** Query the `model_has_permissions` pivot table directly:

```php
use Illuminate\Support\Facades\DB;

$directPermissionNames = DB::table('model_has_permissions')
    ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
    ->where('model_id', $user->id)
    ->where('model_type', get_class($user))
    ->pluck('permissions.name');
```

### 3. Teams support

**Spatie** has built-in teams support via a `team_id` column.

**This package** uses [multi-tenancy](../README.md#multi-tenancy) instead, with Redis key prefixing per tenant. If you use Spatie teams, review the multi-tenancy docs for the equivalent setup.

### 4. Cache store

**Spatie** uses Laravel's Cache facade (configurable: file, database, Redis, etc.).

**This package** uses Redis directly via `illuminate/redis` with custom SET data structures. This provides O(1) membership checks via `SISMEMBER` instead of deserializing cached arrays.

### 5. In-memory caching

**This package** has an additional in-memory cache layer within the same request. The resolution order is: in-memory → Redis → database. This eliminates redundant Redis calls for repeated checks in a single request.

### 6. getAllPermissions() return type

**Spatie** returns an Eloquent `Collection<Permission>` (full models).

**This package** returns a `Collection<PermissionDTO>` (lightweight read-only objects with `name`, `id`, `group`, `guard` properties).

If you access Eloquent-specific methods on the result (like `->pivot` or relationship methods), you will need to adjust.

### 7. Events

| Spatie event | This package event |
|-------------|-------------------|
| `PermissionRegistered` | — |
| `RoleRegistered` | — |
| — | `RolesAssigned` |
| — | `PermissionsSynced` |
| — | `RoleDeleted` |
| — | `UserDeleted` |

If you listen to Spatie events, update your listeners to use this package's events from `Scabarcas\LaravelPermissionsRedis\Events\*`.

### 8. Model customization

**Spatie** allows configuring custom Permission/Role model classes via `config('permission.models')`.

**This package** uses fixed model classes. If you extended Spatie's models, move that logic to your application (e.g., observers, global scopes).

---

## Config mapping

| Spatie (`config/permission.php`) | This package (`config/permissions-redis.php`) | Notes |
|----------------------------------|-----------------------------------------------|-------|
| `models.permission` | — | Not configurable |
| `models.role` | — | Not configurable |
| `table_names.permissions` | `tables.permissions` | Same default: `'permissions'` |
| `table_names.roles` | `tables.roles` | Same default: `'roles'` |
| `table_names.model_has_permissions` | `tables.model_has_permissions` | Same default |
| `table_names.model_has_roles` | `tables.model_has_roles` | Same default |
| `table_names.role_has_permissions` | `tables.role_has_permissions` | Same default |
| `column_names.role_pivot_key` | — | Always `role_id` |
| `column_names.permission_pivot_key` | — | Always `permission_id` |
| `column_names.model_morph_key` | — | Always `model_id` |
| `column_names.team_foreign_key` | `tenancy.resolver` | Use multi-tenancy instead |
| `teams` | `tenancy.enabled` | Different implementation |
| `cache.expiration_time` | `ttl` | In seconds (default: 86400) |
| `cache.key` | `prefix` | Key prefix (default: `'auth:'`) |
| `cache.store` | `redis_connection` | Always Redis |
| `register_permission_check_method` | `register_gate` | Gate::before integration |
| — | `register_middleware` | Auto-register middleware aliases |
| — | `register_blade_directives` | Auto-register Blade directives |
| — | `warm_on_login` | Auto-warm cache on login |
| — | `super_admin_role` | Role that bypasses all checks |
| — | `wildcard_permissions` | fnmatch() wildcard support |
| — | `octane.reset_on_request` | Octane compatibility |
| — | `model_morph_key_type` | UUID/ULID support |

---

## FAQ

### Can I run both packages simultaneously?

Yes, temporarily. Both can coexist during migration as long as they use the same table names. The middleware alias names are the same (`permission`, `role`, `role_or_permission`), so whichever service provider loads last will win. Remove Spatie after verifying everything works.

### What if I have custom table names in Spatie?

The `permissions-redis:migrate-from-spatie` command reads Spatie's config automatically. If `config/permission.php` exists, it uses the table names from `table_names.*`. Otherwise, it uses the defaults.

### Do I need to re-run migrations?

If you're reusing the same table names (recommended), **no**. The migration command adds the missing `description` and `group` columns. If you chose different table names, run `php artisan migrate` before the migration command.

### What about seeded data?

All existing permissions, roles, and assignments carry over. The migration command copies the data and warms the Redis cache.

### I use `getDirectPermissions()` — what do I do?

See [Behavior differences > Direct vs. role-based permission separation](#2-direct-vs-role-based-permission-separation) for a workaround using direct database queries.

### I use Spatie's team feature — what's the equivalent?

Use the [multi-tenancy feature](../README.md#multi-tenancy). Enable it in config and configure a tenant resolver. Redis keys will be prefixed per tenant, providing full isolation.

### My tests use `Spatie\Permission\PermissionRegistrar`

Replace with the `WithPermissions` testing trait or bind `InMemoryPermissionRepository`:

```php
// Before (Spatie)
app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

// After (this package)
use Scabarcas\LaravelPermissionsRedis\Testing\WithPermissions;
uses(WithPermissions::class);
// Then use $this->flushPermissionCache() in tests
```
