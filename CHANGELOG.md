# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.1]: https://github.com/scabarcas/laravel-permissions-redis/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/scabarcas/laravel-permissions-redis/releases/tag/v1.0.0
