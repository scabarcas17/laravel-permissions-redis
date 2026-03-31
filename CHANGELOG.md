# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-03-31

### Added

- **Multi-tenancy support** — `TenantAwareRedisPermissionRepository` decorator that prefixes all cache keys with the current tenant ID, with built-in Stancl/Tenancy resolver and support for custom resolvers.
- **UUID/ULID support** — Configurable `model_morph_key_type` option (`int`, `uuid`, `ulid`) for non-incrementing primary keys.
- **Octane support** — Optional per-request state reset (`octane.reset_on_request`) that flushes in-memory caches between requests in long-lived workers.
- **`permissions-redis:migrate-from-spatie` command** — Automated migration from `spatie/laravel-permission` with chunked inserts, schema compatibility checks, `--dry-run` mode, and post-migration cache warming.
- **`permissions-redis:seed` command** — Declarative role/permission seeding from config with `--fresh` and `--no-warm` options and production safety confirmation.
- **`WithPermissions` test trait** — Testing helper with `seedPermissions()`, `seedRoles()`, `actingAsWithPermissions()`, `actingAsWithRoles()`, and `flushPermissionCache()` for concise test setup.
- **Documentation** — Migration guide from Spatie with method equivalence tables and behavior differences; integration guide for Policies, Sanctum/Passport, and Laravel Pulse.
- **Community governance** — `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, GitHub issue templates, PR template, and funding configuration.
- **CI improvements** — Redis service in GitHub Actions, test matrix for PHP 8.3/8.4 and Laravel 12/13, coverage reporting, and mutation testing with Infection.
- **Test suite expansion** — Added `OctaneSupportTest`, `TenantAwareRepositoryTest`, and `UuidSupportTest`.

### Fixed

- **Octane reset resolving wrong class** — `registerOctaneReset()` now resolves `RedisPermissionRepository` directly instead of `PermissionRepositoryInterface`, which would fail when multi-tenancy was enabled. Also replaced `::class` reference with a string to avoid undefined class errors when Octane is not installed.

## [2.0.0] - 2026-03-27

### Added

- **Guard-scoped resolution** — All permission and role checks (`hasPermission`, `hasRole`, `getAllPermissions`, `getAllRoles`) now accept an optional `?string $guard` parameter, enabling proper multi-guard support throughout the entire resolution pipeline.
- **Guard-aware Redis storage** — Permissions and roles are stored in Redis as `guard|name` encoded entries, allowing the same permission name to exist under different guards without collision.
- **`forGuard()` fluent method** — `$user->forGuard('api')->hasPermissionTo('posts.edit')` to scope a single check to a specific guard.
- **`rewarmAll()` method** — Non-destructive cache rewarm on `AuthorizationCacheManager` that rebuilds without flushing first.
- **`warmPermissionAffectedUsers()` method** — Targeted cache warming for all users affected by a specific permission change.
- **`getUserIdsAffectedByPermission()` method** — Query all user IDs impacted by a permission (direct + role-inherited).
- **Enum support for roles** — `BackedEnum` values are now fully supported in `assignRole`, `syncRoles`, `removeRole`, and all role-checking methods, matching the existing permission enum support.
- **`PermissionDTO` as `readonly` class** — Immutable data transfer object using PHP 8.2+ `readonly` class syntax.
- **Permission model `deleting` hook** — Automatically cleans up pivot table entries when a permission is deleted.
- **Role model `syncPermissions()`, `givePermissionTo()`, `revokePermissionTo()`** — Fluent permission management methods directly on the Role model with automatic cache invalidation.
- **Comprehensive test suite expansion** — Added `BladeDirectivesTest`, `RedisPermissionRepositoryTest`, `ModelsTest`, `ServiceProviderTest`, and significantly expanded `HasRedisPermissionsTest`, `CacheManagerWarmTest`, `CommandsTest`, and `PermissionResolverTest` (+1,500 lines of tests).
- **Test enum fixtures** — `TestPermissionEnum` and `TestRoleEnum` for typed enum testing.

### Changed

- **Breaking: `PermissionResolverInterface`** — `hasPermission()`, `hasRole()`, `getAllPermissions()`, and `getAllRoles()` signatures now include an optional `$guard` parameter.
- **Breaking: Redis key format** — Cached entries now use `guard|name` encoding instead of plain names. Existing caches must be flushed and rewarmed after upgrading.
- **Blade directives refactored** — Extracted common authentication logic into `resolveForUser()` helper; all directives now pass the current guard to the resolver.
- **Middleware guard-awareness** — `PermissionMiddleware`, `RoleMiddleware`, and `RoleOrPermissionMiddleware` now resolve the guard from the current authentication driver.
- **Smarter `warmAllUsers()`** — No longer scans the entire users table; queries only users that have at least one role or direct permission assigned.
- **`RedisPermissionRepository` caching** — Connection, prefix, and TTL are now lazily cached in instance properties to avoid repeated config lookups.
- **`UnauthorizedException` improvements** — Cleaner construction with type-safe parameters.

### Fixed

- **Guard isolation** — Previously, permissions assigned under one guard (e.g., `api`) could bleed into checks under another guard (e.g., `web`). All checks are now guard-scoped.

## [1.1.0] - 2026-03-20

### Added

- **Laravel 13 support** — Added `^13.0` constraint to all `illuminate/*` dependencies, making the package fully compatible with Laravel 11, 12, and 13.
- **Orchestra Testbench 11** — Added `^11.0` to dev dependencies for Laravel 13 testing support.

## [1.0.1] - 2026-03-19

### Changed

- **Migration filename** — renamed to `0000_00_00_000000_create_permission_tables.php` so it loads with a neutral timestamp and does not conflict with application migrations regardless of publish order.
- **Default publish group** — config and migrations are now also published via `php artisan vendor:publish --provider` (no tag required), in addition to the explicit `permissions-redis-migrations` tag.

## [1.0.0] - 2026-03-19

First stable release of `scabarcas/laravel-permissions-redis`.

### Added

- **Core authorization system** — Eloquent models for `Permission` and `Role` with `findOrCreate` semantics, guard scoping, and polymorphic user relationships across 5 database tables.
- **`HasRedisPermissions` trait** — User model mixin providing the full API: `assignRole`, `syncRoles`, `removeRole`, `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `hasPermissionTo`, `hasAnyPermission`, `hasAllPermissions`, `hasRole`, `hasAnyRole`, `hasAllRoles`, `getAllPermissions`, `getPermissionNames`, `getRoleNames`.
- **Redis-first resolution** — `PermissionResolver` with dual-layer caching (in-memory PHP array + Redis SETs) and automatic cold-start warming from the database.
- **`RedisPermissionRepository`** — Low-level Redis operations using SET data structures (`SISMEMBER`, `SMEMBERS`, `SADD`) with `MULTI/EXEC` atomic transactions and configurable TTL.
- **`AuthorizationCacheManager`** — Cache orchestrator with `warmAll`, `warmUser`, `warmRole`, `evictUser`, and `evictRole` methods for full database-to-Redis synchronization.
- **Automatic cache invalidation** — Event-driven system via `CacheInvalidator` listener handling `RolesAssigned`, `PermissionsSynced`, `RoleDeleted`, and `UserDeleted` events.
- **Cache warming on login** — Optional `WarmCacheOnLogin` listener that automatically warms user permissions on `Illuminate\Auth\Events\Login`.
- **Route middleware** — `permission`, `role`, and `role_or_permission` middleware with support for OR (`|`) and AND (`&`) operators.
- **Blade directives** — `@role`, `@endrole`, `@hasanyrole`, `@endhasanyrole`, `@hasallroles`, `@endhasallroles`, `@permission`, `@endpermission`, `@hasanypermission`, `@endhasanypermission`, `@hasallpermissions`, `@endhasallpermissions`.
- **Laravel Gate integration** — Optional `Gate::before` callback that resolves `$user->can('permission.name')` through Redis.
- **Wildcard permissions** — Optional `fnmatch()` pattern matching (e.g., `users.*` matches `users.create`, `users.edit`).
- **Super admin role** — Configurable role that bypasses all permission checks.
- **Artisan commands** — `permissions-redis:warm`, `permissions-redis:warm-user`, `permissions-redis:flush`, `permissions-redis:stats`.
- **Flexible parameter types** — All assignment and check methods accept `string`, `int` (ID), `BackedEnum`, `array`, or `Collection`.
- **Query scopes** — `User::role('admin')` and `User::permission('posts.edit')` for Eloquent filtering.
- **`PermissionDTO`** — Lightweight immutable data transfer object for permission data.
- **Configurable table names** — All 5 tables can be renamed via config to avoid conflicts.
- **Publishable config** — `config/permissions-redis.php` with env variable support for all runtime options.
- **Database migrations** — Auto-loaded migration for the 5 permission tables with foreign keys and composite primary keys.
- **Comprehensive test suite** — Unit and integration tests using Pest with `InMemoryPermissionRepository` fixture for testing without Redis.
- **Documentation** — README with installation guide, usage examples, conventions, API reference, and C4 architecture diagrams.

[3.0.0]: https://github.com/scabarcas/laravel-permissions-redis/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/scabarcas/laravel-permissions-redis/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/scabarcas/laravel-permissions-redis/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/scabarcas/laravel-permissions-redis/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/scabarcas/laravel-permissions-redis/releases/tag/v1.0.0
