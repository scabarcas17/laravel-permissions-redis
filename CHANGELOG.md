# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0-beta.2] - 2026-04-22

Maintenance release of the 4.x beta. Fixes all issues surfaced by a deep audit
of beta.1 — data-consistency bugs, atomicity gaps, and cross-implementation
divergences between the in-memory test fake and the real Redis repository.

### Breaking Changes

- **`PermissionRepositoryInterface::replacePermissionGroups(array $groups): void`** — New method on the contract. Atomic rebuild of the permission groups hash. Any custom implementation of the interface must implement it.
- **`AuthorizationCacheManager::warmAll()` now flushes first.** In beta.1 both `warmAll()` and `rewarmAll()` had identical no-flush bodies (flag was misleading). `warmAll()` now runs `flushAll()` before repopulating — deleted users/roles/permissions cannot survive as stale keys. Use `rewarmAll()` when a momentary read-downtime window is unacceptable.
- **`PermissionDTO::$id` removed** — the property was declared but never populated by the resolver. If you read it, switch to looking up the `Permission` model directly.
- **`batchResolveRoleIds`/`batchResolvePermissionIds` validate integer IDs against the target guard** — passing an ID that belongs to a different guard now silently drops it (matches the behavior already applied to string/enum names). Previously integer IDs bypassed the guard filter.
- **Middleware trims whitespace on OR (`|`) lists.** `permission:'users.read | users.write'` now behaves the same as `permission:'users.read|users.write'`. Previously only the `&` separator trimmed.

### Added

- **`tests/Redis/` contract suite** — Every critical flow (set/has, empty sentinel, `replaceSetBatch` atomicity, tenant prefix, permission groups, TTL, `flushAll`) is now exercised against **a real Redis instance** via predis. Auto-skips when Redis is unavailable. Run with `./vendor/bin/pest --testsuite Redis`. Env overrides: `PERMISSIONS_REDIS_TEST_HOST`, `PERMISSIONS_REDIS_TEST_PORT`, `PERMISSIONS_REDIS_TEST_DB`, `PERMISSIONS_REDIS_TEST_SKIP=1`.
- **`ScansRedisByPrefix` trait** — Shared scan helper that applies the Redis client's built-in prefix to SCAN MATCH patterns (neither predis nor phpredis auto-prefix the MATCH parameter). Used by `RedisPermissionRepository::flushAll`, `TenantAwareRedisPermissionRepository::flushAll`, and `CacheStatsCommand`.
- **`hasAnyRole` / `hasAllRoles` accept a `Collection`** — consistent with `hasRole`. Previously only plain arrays and variadic args were handled.
- **Regression tests for every fix** — 36 new tests covering BUGs 1-10 and I-1..I-9 from the audit.

### Fixed

- **`warmPermissionGroups` left stale metadata** (BUG-2). Deletion of a permission in the DB did not remove its entry from the `permission_groups` hash during a rewarm. The hash is now rebuilt atomically via `replacePermissionGroups` (DEL + HSET in MULTI/EXEC).
- **`SeedCommand --fresh` corrupted Redis cache state** (BUG-3). `Role::query()->delete()` and `Permission::query()->delete()` bypassed model events, so the `deleted` hooks that clean up the permission groups hash and the role set keys never ran. Now wrapped in `withoutEvents(...)` with an explicit `flushAll()` afterwards to restore consistency.
- **`warmAll()` and `rewarmAll()` did the same thing** (BUG-1). See Breaking Changes above.
- **`warmUser` / `warmRole` were not atomic** (BUG-8). Permissions and roles were written in two separate MULTI/EXEC transactions; a concurrent reader could observe the new permission set alongside the old role set (or vice versa). Both methods now use `replaceSetBatch` for a single transaction.
- **`PermissionResolver::evictIfNeeded` only triggered when `permissionCache` grew** (BUG-5). Callers that only exercised `getAllPermissions()` could grow `allPermissionsCache` unbounded. The trigger now checks the max size across all per-user caches, and only the exact overflow is evicted.
- **`PermissionResolver::decodeEntry` crashed outside HTTP context** (BUG-4). `auth()->getDefaultDriver()` throws in some queue/console setups. Now guarded with a try/catch and falls back to `config('auth.defaults.guard', 'web')`.
- **Cross-user group staleness in the resolver** (BUG-10). The per-user `permissionGroupsCache` has been removed; group metadata is fetched on every `getAllPermissions()` call (cheap HMGET) so admin-side group edits are immediately visible.
- **`hasAnyRole` / `hasAllRoles` inconsistency with `hasRole`** (BUG-7). Both now accept Collections via `collect(...)->flatten()`.
- **`scopeRole` / `scopePermission` ignored guard when given integer IDs** (BUG-9). Fixed: integer IDs are validated against the target guard before inclusion in the scope.
- **`Gate::before` always resolved to `auth()->getDefaultDriver()`** (I-5). `Gate::forUser($apiUser)->allows(...)` now finds the guard whose authenticated user matches the target instance, falling back to the default.
- **`HMSET` (deprecated in Redis 4.0) replaced with `HSET`** multi-field (BUG-6). Field/value pairs are now flattened so predis can serialize them correctly.
- **`CacheStatsCommand` and `flushAll` SCAN MATCH pattern** missed the Redis client's built-in prefix, so on installations where Laravel's `database.redis.options.prefix` is configured (the default `laravel_database_`), no keys matched. The new `ScansRedisByPrefix` trait handles this correctly for both predis and phpredis.
- **`Role` model static `$roleIdNameCache`** is now flushed on role rename and role deletion (I-6). Previously stale names could persist within a queue worker's memory.
- **`WithPermissions::seedRoles` threw `Array to string conversion` on numerically-indexed input** (I-2). Now validates and throws a clear `InvalidArgumentException`.
- **Migration column lengths pinned to 191** (I-7) so the `(name, guard_name)` unique index fits within MySQL's 767-byte key limit under utf8mb4 on MySQL < 5.7.7.
- **`ensureUserCacheExists` warning demoted to debug** (I-9). In high-traffic deployments with cold caches, the warning produced significant log noise without actionable signal.
- **Middleware OR (`|`) list whitespace trimming** — see Breaking Changes.

### Migration from beta.1

1. If you implement `PermissionRepositoryInterface` in your own code (adapters, test doubles), add `replacePermissionGroups(array $groups): void`.
2. If you relied on `warmAll()` not flushing, switch the call to `rewarmAll()`.
3. If you read `PermissionDTO::$id`, remove that code path (it was always `null`).
4. If you pass role/permission integer IDs via a guard that doesn't own them (cross-guard leakage), expect them to be dropped silently — intentionally.

## [4.0.0-beta.1] - 2026-04-22

### Breaking Changes

- **`PermissionRepositoryInterface`** — Three new methods added for permission group metadata. Any custom implementation of the interface must implement them:
  - `setPermissionGroups(array $groups): void`
  - `getPermissionGroups(array $encodedNames): array`
  - `deletePermissionGroup(string $encodedName): void`
- **Post-upgrade step required** — After upgrading, run `php artisan permissions-redis:warm --fresh` (or `rewarm`) to populate the new `auth:permission_groups` Redis hash. Until the hash is populated, `getAllPermissions()` will return DTOs with `group: null`.

### Added

- **Permission `group` metadata preserved in Redis** — A new Redis hash (`{prefix}permission_groups`) stores `{guard}|{name}` → `group` mappings. `PermissionResolver::getAllPermissions()` now enriches `PermissionDTO` with the correct group name. Groups are global (not tenant-scoped) because they live in the shared `permissions` table.
- **`Role::hasPermission(string $permission, ?string $guard = null): bool`** — Check whether a specific role has a permission via Redis (SISMEMBER on the `role:{id}:permissions` set).
- **Guard parameter on Blade directives** — All six directives (`@role`, `@hasanyrole`, `@hasallroles`, `@permission`, `@hasanypermission`, `@hasallpermissions`) now accept an optional second argument for guard override: `@role('admin', 'api')`.
- **`PermissionsAssigned` event** — Dispatched when `givePermissionTo`, `revokePermissionTo`, or `syncPermissions` is called directly on a user. Subscribed by `CacheInvalidator` to trigger rewarming.
- **`DispatchesPermissionEvents` trait** — Opt-in trait for User models that automatically dispatches `UserDeleted` on model deletion. Documented usage replaces manual observer registration.
- **Multiple user models supported** — `user_model` config now accepts an array: `['App\Models\User', 'App\Models\Admin']`. `AuthorizationCacheManager` and the `Gate::before` callback iterate over all configured types.
- **Queue-backed cache warming** — `WarmUserCacheJob` plus `--queue` flag on `permissions-redis:warm` and `permissions-redis:warm-user` commands. Pushes warm operations to the default queue instead of running them synchronously.
- **Guard configuration per seed entry** — `config/permissions-redis.php` `seed` structure now supports `['guard' => 'api']` on roles and permissions. `permissions-redis:seed` also accepts `--guard` as a global override.
- **LRU eviction in `PermissionResolver`** — In-memory resolver caches (`permissionCache`, `roleCache`, etc.) now evict the oldest half once they exceed `resolver_cache_limit` (default `1000`). Prevents unbounded growth in long-running workers.
- **Warm cooldown / rate limiting** — `ensureUserCacheExists` tracks the last warm attempt per user in `warmAttempts` and skips re-warming for `resolver_warm_cooldown` seconds (default `1.0s`). Protects the database from storms if Redis cache creation repeatedly fails.
- **`TransactionFailedException`** — Thrown when Redis `EXEC` returns `null` or `false`, making transaction failures observable instead of silently dropping writes.
- **`AuthorizationCacheManager::rewarmAll()`** — Non-destructive rewarm that writes the new cache before evicting stale keys, eliminating the downtime window present in the old `warmAll()` flow.
- **`RedisPermissionRepository::replaceSetBatch(array $sets): void`** — Pipeline-backed batch method for bulk warming. Used internally by `rewarmAll` to reduce round-trips during bulk operations.
- **`isSuperAdmin` single-call optimization** — Replaces per-guard `SISMEMBER` probes with one `SMEMBERS` followed by in-memory decoding.
- **Role ID cache in `HasRedisPermissions::hasRole`** — Numeric role IDs are resolved via a static map (`int → name`) instead of one SQL query per check.

### Changed

- **`CacheStatsCommand` regex** — Now matches non-numeric user IDs (UUIDs, ULIDs). Previous `/^user:(\d+):permissions$/` pattern silently excluded them from stats.
- **`SeedCommand` guard handling** — No longer hardcoded to `'web'`. Reads from config per-entry, accepts `--guard=api`, or falls back to the application default guard.
- **`MigrateFromSpatieCommand::ensureTargetTablesExist`** — Returns `bool`; `handle()` halts when it returns `false` instead of proceeding with `copyData()` and producing partial migrations.
- **`TenantAwareRedisPermissionRepository`** — All role-related operations (`setRolePermissions`, `setRoleUsers`, `getRoleUserIds`, `deleteRoleCache`) now apply the tenant prefix. Previously, role data was shared across tenants. `flushAll()` is scoped to the current tenant (`SCAN` with `t:{tenantId}:*`).
- **`PermissionResolver::isSuperAdmin`** — Iterates `loadUserRoles()` once instead of calling `userHasRole` per configured guard.
- **Empty SET sentinel** — The legacy `'__empty__'` marker is replaced by a reserved prefix (`"\x00empty\x00"`) so permissions literally named `__empty__` are no longer filtered out silently.

### Fixed

- **Tenancy isolation leak on roles** — Role cache keys in multi-tenant deployments are now per-tenant, preventing one tenant from reading or mutating another tenant's role/permission pivot data.
- **`flushAll` in multi-tenant deployments** — The decorator no longer clears every tenant's keys; it scans and deletes only the current tenant's prefix space.

### Upgrade Guide

From `v3.0.0` → `v4.0.0-beta.1`:

1. **Run `composer require scabarcas/laravel-permissions-redis:^4.0@beta`**.
2. **If you implement `PermissionRepositoryInterface`** yourself, add the three new methods (`setPermissionGroups`, `getPermissionGroups`, `deletePermissionGroup`). See `RedisPermissionRepository` for a reference implementation.
3. **Run `php artisan permissions-redis:warm --fresh`** to populate the new `auth:permission_groups` Redis hash. Without this, `getAllPermissions()` returns DTOs with `group: null`.
4. **Optional** — Publish the refreshed config (`php artisan vendor:publish --tag=permissions-redis-config`) to pick up the new `resolver_cache_limit`, `resolver_warm_cooldown`, and `queue` options.

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

[4.0.0-beta.2]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.0.0-beta.1...v4.0.0-beta.2
[4.0.0-beta.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v3.0.0...v4.0.0-beta.1
[3.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/releases/tag/v1.0.0
