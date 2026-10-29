# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.2.0] - 2026-10-29

Minor release: opt-in morph map support, a queue section in the config, and
user deletion that cleans up after itself. No breaking changes;
`DispatchesPermissionEvents` is deprecated but keeps working.

### Added

- **`use_morph_map` config option.** With it on, the pivot tables store the user model's morph alias in `model_type` (`$user->getMorphClass()`, so `user` under `Relation::enforceMorphMap(['user' => User::class])`) instead of the class name, which is what `spatie/laravel-permission` writes. The relations, the query scopes, `Role::users()`, the cache warmer, the invalidation listener and the Spatie migration command all read and write the same value, so rows migrated from Spatie under a morph map are used as-is and the column matches the application's other polymorphic tables. Off (the default through 4.x), nothing changes: the class name is used even when a morph map exists. Turning it on for an installation that already has rows requires rewriting them once, since rows that still carry the class name are not read afterwards; the README has the one-off update. Under an enforced morph map every configured user model must be in the map, as with any morph relation. `php artisan about` reports the setting. The default becomes `true` in 5.0. Env: `PERMISSIONS_REDIS_USE_MORPH_MAP`.
- **`queue.connection` / `queue.name` config.** The connection and queue for `WarmAllCacheJob` and `WarmUserCacheJob`, applied in the job constructor so jobs dispatched from application code honour them too; `--queue=connection` still overrides the connection for that run. `null` keeps the default queue connection and that connection's default queue. Env: `PERMISSIONS_REDIS_QUEUE_CONNECTION`, `PERMISSIONS_REDIS_QUEUE`. (The README documented these two keys between `4.0.0-beta.1` and `4.1.1` although they did not exist; if you added them to your published config back then, they take effect now.)
- **`UserDeleted` carries `$affectedRoleIds`.** The roles the user held, captured before the pivot rows are removed. `CacheInvalidator::handleUserDeleted()` rebuilds those `role:{id}:users` indexes so the deleted user drops out of them instead of lingering until the TTL. Dispatching the event with the user ID alone, as before, still evicts the user cache.
- **`permissions-redis:migrate-from-spatie` warns about `model_type` values it will not read.** After the summary (in dry runs too) the command lists each value found in Spatie's pivot tables that is not one of the configured user model types, with its row count, the types the package reads, and the hint to enable `use_morph_map` when the value is a morph alias. Previously such rows were copied in silence and every warm ignored them, which looked like a loss of permissions.

### Fixed

- **Deleting a user left its `model_has_roles` / `model_has_permissions` rows behind.** The pivots are polymorphic, so no foreign key removes them with the user, and nothing in the package did either. Besides the orphans, that is a hazard wherever IDs get reused (imports with fixed IDs, truncate-and-reseed): the next user with that ID inherited the roles. `HasRedisPermissions` now hooks `deleted`: a hard delete (`delete()` on a model without `SoftDeletes`, or `forceDelete()`) detaches both pivots, a soft delete keeps the rows for `restore()`, as Spatie does. Spatie detaches in `deleting`; doing it once the row is gone means a delete that fails (a foreign key from another table, for instance) leaves the assignments untouched. Either way `UserDeleted` is dispatched, the user's Redis entry evicted and the resolver's in-memory copy dropped, so `DispatchesPermissionEvents` is no longer needed for that. **If you dispatch `UserDeleted` yourself from a `deleted` hook, as an earlier README suggested, remove it: the trait does it now, and keeping both fires the event (and any listener of yours) twice.** Mass deletes through the query builder (`User::where(...)->delete()`) fire no model events, as everywhere in Eloquent, and leave rows and cache behind; the README shows the two ways around it.

### Changed

- **`DispatchesPermissionEvents` is deprecated.** It is a no-op on a model that also uses `HasRedisPermissions` (so models set up with both traits dispatch `UserDeleted` once, not twice) and still dispatches on models that use it alone. Removed in 5.0.
- **The coverage job runs against Redis 7, once per client.** Without a server the Redis suite was skipped in that job, so the coverage of `RedisPermissionRepository` and the SCAN helper came from mocks alone. The two runs (predis, phpredis) upload separately and Codecov merges them.

### Docs

- README: `use_morph_map` and `queue.*` in the config table, a Morph Maps section with the upgrade rewrite, the queue section no longer claims there is no queue config, the invalidation and events tables describe what deletion does now, and the mass-delete caveat replaces the `DispatchesPermissionEvents` snippet.
- Migration guide: with `use_morph_map` on, rows written by Spatie under a morph map need no rewrite; the FAQ keeps the rewrite as the alternative for those who prefer the class name.

## [4.1.2] - 2026-10-14

Patch release: a 4.1.1 regression for predis on Laravel 11 / 12, Spatie-style
model arguments, guard validation on the `Role` model, caches going stale
after a role rename and inside queue workers, and two fixes to the Spatie
migration command on PostgreSQL and large pivots. No breaking changes.

### Fixed

- **`flushAll()`, `permissions-redis:flush`, `permissions-redis:stats`, `permissions-redis:warm`, `permissions-redis:seed --fresh` and `migrate-from-spatie` threw `ERR invalid cursor` with predis on Laravel 11 and 12 (regression in 4.1.1).** The phpredis fix in 4.1.1 made the shared SCAN helper start from a `null` cursor, which phpredis requires, and CI passed with both clients because the Redis jobs install Laravel 13, whose `PredisConnection::scan()` maps `null` to `0`. Laravel 11 and 12 have no such method: the call reaches predis as-is, predis serialises `null` as an empty string and the server rejects it, so every full-cache operation failed for predis users on those versions. The helper now starts from `null` only when the connection's client is phpredis and from `0` otherwise, and the Redis 7 integration job runs on Laravel 12 and 13 so the two `PredisConnection` implementations are both exercised.
- **`Role::syncPermissions()` / `givePermissionTo()` / `revokePermissionTo()` ignored the guard for integer IDs and mangled model instances.** Integer IDs were attached without checking the permission's guard, so a `web` role could be given an `api` permission by ID (the user trait has validated this since `4.0.0-beta.2`). A `Permission` instance was cast to `0` and attached as `permission_id = 0` (a foreign-key error on MySQL / PostgreSQL, a junk row on SQLite). IDs and instances are now validated against the role's guard and silently dropped when they belong to another one, consistent with the trait; any other object throws an `InvalidArgumentException` naming the type.
- **`Role` / `Permission` instances crashed the user trait.** The Spatie idiom `$user->assignRole($role)` (and `syncRoles`, `removeRole`, `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, the query scopes) hit a catch-all `(int)` cast and threw "could not be converted to int"; `hasRole($role)` cast the model to an array and threw "Array to string conversion"; `hasAnyPermission($permission)` threw on the string cast. Model instances are now accepted wherever the parameter is `mixed`: assignment methods and scopes use the model key (validated against the guard like an integer ID), `hasRole()` uses the role's name and, unless a guard is given, its own `guard_name`. Unsupported objects throw an `InvalidArgumentException` instead of a cast error. `hasPermissionTo()` keeps its `string|BackedEnum` signature.
- **Renaming a role (or changing its guard) left every cache stale.** The `saved` hook only tried to clear the static id => name memo, and did so by calling the static method on the trait itself, which PHP 8.1+ deprecates and which clears the trait's own copy rather than the user model's (a static property declared in a trait is duplicated into each using class). Nothing rewarmed the `user:{id}:roles` sets either, so after renaming `editor` to `writer`, `hasRole('writer')` stayed false, `hasRole('editor')` stayed true and `getRoleNames()` kept answering `editor` until the TTL expired. The memo now lives on `Role` (`Role::nameById()` / `Role::flushIdNameCache()`, both `@internal`), and an update that changes `name` or `guard_name` rewarms every user holding the role and drops their in-memory entries. The Octane reset made the same ineffective call and is fixed as well; `HasRedisPermissions::flushRoleIdNameCache()` remains as a delegating shim.
- **Renaming a permission left its old entry in the `permission_groups` hash.** The field for the previous `guard|name` is now removed when `name` or `guard_name` changes; the users and roles holding the permission were already rewarmed.
- **`Role::users()` returned rows of other models and crashed with a multi-model `user_model`.** The relation did not filter `model_type`, so with two user models sharing an ID space the wrong users came back, and an array `user_model` reached `belongsToMany()` as-is ("Class name must be a valid object or a string"). It now targets the first configured model, filters the pivot by its `model_type`, and `attach()` through it writes that `model_type`.
- **`WarmCacheOnLogin` crashed on non-Eloquent users and warmed models it does not manage.** The listener called `getKey()` on the `Login` event's user, which `GenericUser` and other custom `Authenticatable` implementations do not have, and it warmed whatever model logged in even when it was not among the configured user models, writing a cache entry under that ID. It now uses `getAuthIdentifier()` and skips users that are not an instance of a configured model.
- **The resolver's warm-cooldown timestamps were never evicted.** `warmAttempts` was left out of the in-memory eviction, so a long-running worker accumulated one entry per user that ever missed the Redis cache. Every per-user map is now trimmed to `resolver_cache_limit` independently (oldest entries first), the cooldown timestamps included.
- **Queue workers could serve stale answers indefinitely.** The resolver's in-memory caches live in the container singleton, which a `queue:work` process keeps across jobs, so a permission revoked (and rewarmed in Redis) by another process was still reported as granted by the worker until it restarted. The package now resets its in-memory state on `Illuminate\Queue\Events\JobProcessing`, the same reset Octane gets per request. Redis is untouched; only the per-process memos are dropped.
- **`permissions-redis:migrate-from-spatie` left the `id` sequences behind on PostgreSQL.** The copy keeps Spatie's IDs so the pivots stay valid, and PostgreSQL sequences do not advance on inserts with explicit IDs, so the first `Permission::create()` / `Role::create()` after migrating failed with a duplicate key. The sequences of the copied tables are now moved past `MAX(id)` (PostgreSQL only; no-op elsewhere).
- **`permissions-redis:migrate-from-spatie` could skip or duplicate pivot rows past the first 500.** Pivot tables were paginated with `chunk()` ordered by their first column only, which is not unique (every row of a role shares `role_id`), and MySQL / PostgreSQL do not guarantee a stable order among ties between pages. The copies now order by every pivot column.

### Docs

- v4.1.1 was tagged with its notes still under "Unreleased"; the heading and compare link are restored below.
- README: the `resolver_cache_limit` / `resolver_warm_cooldown` env variables are `PERMISSIONS_REDIS_RESOLVER_CACHE_LIMIT` and `PERMISSIONS_REDIS_RESOLVER_WARM_COOLDOWN`; the table and the cache-miss section named two variables the config never read. The `queue.connection` / `queue.name` options listed since `4.0.0-beta.1` never existed: `--queue[=connection]` is the only knob, `permissions-redis:warm --queue` dispatches one `WarmAllCacheJob` (not a `WarmUserCacheJob` per user), and jobs go to the connection's default queue. `getUserIdsAffectedByPermission()` takes a permission ID and queries the database; it does not read Redis. The key list now includes the `permission_groups` hash, the config table lists the tenancy, Octane and morph-key options, and the invalidation table lists role and permission renames.
- Migration guide: rows written by Spatie under a morph map carry the alias in `model_type`, while 4.x reads and writes the class name, so they stay invisible until rewritten. The guide now says so and shows the one-off update.

## [4.1.1] - 2026-10-08

Patch release: the two phpredis regressions and the contract suite that
would have caught them.

### Fixed

- **`getAllPermissions()` threw under phpredis.** `RedisPermissionRepository::getPermissionGroups()` spread the HMGET fields into separate arguments. predis accepts that shape, but phpredis exposes `hMGet(string $key, array $fields)` and rejects it with an `ArgumentCountError` (two or more permissions) or a `TypeError` (a single one), so every `getAllPermissions()` / `getPermissionNames()` call failed on installations using the `phpredis` client, and applications wrapping the call defensively saw users with no permissions at all. `hasPermission()` was not affected. The fields now travel as a single array (the shape Laravel's own `PhpRedisConnection::hmget()` uses) and the reply is normalised: predis answers with a positional list, phpredis with a map keyed by field and `false` for missing fields.
- **`flushAll()`, `permissions-redis:flush` and `permissions-redis:stats` were silent no-ops under phpredis.** The shared SCAN helper started from a `"0"` cursor, which phpredis treats as an already finished scan and answers with `false` without querying the server, and passed `MATCH` / `COUNT` in upper case, which `PhpRedisConnection::scan()` ignores in favour of `*`. The helper now starts from a `null` cursor, uses lower-case option keys (accepted by both clients) and handles the `false` reply. Fixing the cursor alone would have turned `flushAll()` into a delete of every key in the database, so both changes ship together with a contract test asserting that keys outside the package prefix survive.

### Added

- **The Redis contract suite runs against both clients in CI.** `tests/Redis/` hard-coded `predis`, which is why neither bug surfaced even though CI installs the `redis` extension. New `PERMISSIONS_REDIS_TEST_CLIENT` env override (`predis`, the default, or `phpredis`); the two Redis integration jobs now run once per client, and the suite's own key cleanup is client-agnostic.

## [4.1.0] - 2026-08-19

Minor release: closes the two remaining functional gaps against Spatie's API
listed in the README comparison, and adds PHP 8.5 to the CI matrix. No
breaking changes.

### Added

- **`getDirectPermissions()` / `getPermissionsViaRoles()` on `HasRedisPermissions`.** Spatie-compatible getters for distinguishing directly assigned from role-inherited permissions at runtime. Both read the Eloquent relations (SQL), like the query scopes, and return `Permission` models rather than DTOs; permission *checks* keep resolving from the merged Redis set. Guard-scoped like the other getters (optional `$guard` parameter, `forGuard()` supported), filtering on each permission's own `guard_name`, which matches what the cache warmer encodes. Permissions granted by several roles are returned once.
- **`permissions-redis:flush --force`.** Skips the confirmation prompt so the command can run in deploy scripts and CI, where the interactive prompt would answer "no" and silently leave the cache untouched.
- **`php artisan about` section.** Shows the installed package version, Redis connection, key prefix, cache TTL, and the tenancy / wildcard / super admin status. Registered only when `AboutCommand` exists, since the package depends on `illuminate/*` components rather than the full framework.
- **PHP 8.5 in the CI matrix.** Test rows for PHP 8.5 on Laravel 12 and 13, and the Redis 7 integration job now runs on 8.5. The composer constraint (`^8.3`) already allowed PHP 8.5; the full suite passes on 8.5 with no code changes.

### Changed

- **`syncPermissions()` is now variadic on the user trait and the `Role` model,** matching `syncRoles()`: `$user->syncPermissions('a', 'b')` works alongside the existing `syncPermissions(['a', 'b'])` array form. Calling it with no arguments removes every direct (or role) permission, consistent with `sync` semantics; previously the empty array had to be passed explicitly.

### Docs

- **Laravel 11 support is documented as best-effort.** The composer constraint allows Laravel 11, but CI only exercises Laravel 12 and 13 because the Pest 4 harness requires testbench 10+, which starts at Laravel 12. Laravel 11 has been EOL since March 2026; the constraint will be dropped in v5.
- The `getAllPermissions()` README example still showed the `id` field removed from `PermissionDTO` in `4.0.0-beta.2`.
- Migration guide: the direct-vs-role-inherited section and FAQ now point to the new getters instead of a raw pivot-table query workaround.

## [4.0.1] - 2026-07-31

Patch release: cache-consistency fixes for role deletion and role permission
checks after TTL expiry, plus a packaging fix for the testing repository.

### Fixed

- **`InMemoryPermissionRepository` now ships with the package.** The README pointed to `Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\InMemoryPermissionRepository`, but `tests/` is dev-only autoload and excluded from the dist archive, so the class never reached installed apps. It now lives at `Scabarcas\LaravelPermissionsRedis\Testing\InMemoryPermissionRepository`, next to the `WithPermissions` trait.
- **Deleting a role could leave its users with stale cached permissions.** The `RoleDeleted` listener read the affected user list from the Redis `role:{id}:users` index after the database delete; if that index had expired (TTL), the list came back empty and no user cache was rewarmed, while the pivot rows were already gone via FK cascade. Affected user IDs are now captured from the database in `Role::deleting` and carried on the event (`RoleDeleted::$affectedUserIds`, optional constructor argument, so existing dispatch sites keep working). The Redis index remains as fallback for manually-dispatched events.
- **`Role::hasPermission()` answered false on an expired role cache.** The check was a bare `SISMEMBER` with no warm-on-miss (the user-side path has one via `ensureUserCacheExists`). A miss now rewarms the role from the database and re-checks before answering false, throttled per role with the same `resolver_warm_cooldown` the resolver uses, so genuinely absent permissions cannot storm the database.

### Added

- **Tests and docs for the `@unlessrole` / `@unlesspermission` Blade directives.** They have always worked (`Blade::if` registers an `unless` variant for every conditional) but were undocumented and marked as missing in the comparison table. Note the closing tag differs from Spatie: `@endrole` / `@endpermission`, not `@endunlessrole`.
- **composer.json `support`, `funding`, and `suggest` blocks.**

### Docs

- The multi-tenancy section claimed role keys are shared across tenants; they are tenant-prefixed like user keys (`auth:role:t:{tenant}:{id}:*`).
- `PermissionsAssigned` was missing from the API-reference events table.

## [4.0.0] - 2026-05-14

Stable release of the 4.x line. No code changes since `v4.0.0-beta.2`; three
weeks of internal production usage without surfacing new bugs validated the
public API as stable.

### Notes

- The benchmark numbers in the README and the
  [benchmark repository](https://github.com/scabarcas17/laravel-permissions-redis-benchmark)
  apply unchanged to `v4.0.0` because no runtime code changed between
  `v4.0.0-beta.2` and this release.
- Semantic versioning from this point forward: breaking changes will only ship
  in `v5.0.0`. The 4.x line will receive non-breaking features and bug fixes
  only.

## [4.0.0-beta.2] - 2026-04-27

Maintenance release of the 4.x beta. Fixes all issues surfaced by a deep audit
of beta.1: data-consistency bugs, atomicity gaps, and cross-implementation
divergences between the in-memory test fake and the real Redis repository.

### Breaking Changes

- **`PermissionRepositoryInterface::replacePermissionGroups(array $groups): void`.** New method on the contract. Atomic rebuild of the permission groups hash. Any custom implementation of the interface must implement it.
- **`AuthorizationCacheManager::warmAll()` now flushes first.** In beta.1 both `warmAll()` and `rewarmAll()` had identical no-flush bodies (flag was misleading). `warmAll()` now runs `flushAll()` before repopulating, so deleted users/roles/permissions cannot survive as stale keys. Use `rewarmAll()` when a momentary read-downtime window is unacceptable.
- **`PermissionDTO::$id` removed.** The property was declared but never populated by the resolver. If you read it, switch to looking up the `Permission` model directly.
- **`batchResolveRoleIds`/`batchResolvePermissionIds` validate integer IDs against the target guard.** Passing an ID that belongs to a different guard now silently drops it (matches the behavior already applied to string/enum names). Previously integer IDs bypassed the guard filter.
- **Middleware trims whitespace on OR (`|`) lists.** `permission:'users.read | users.write'` now behaves the same as `permission:'users.read|users.write'`. Previously only the `&` separator did.

### Added

- **`tests/Redis/` contract suite.** Every critical flow (set/has, empty sentinel, `replaceSetBatch` atomicity, tenant prefix, permission groups, TTL, `flushAll`) is now exercised against **a real Redis instance** via predis. Auto-skips when Redis is unavailable. Run with `./vendor/bin/pest --testsuite Redis`. Env overrides: `PERMISSIONS_REDIS_TEST_HOST`, `PERMISSIONS_REDIS_TEST_PORT`, `PERMISSIONS_REDIS_TEST_DB`, `PERMISSIONS_REDIS_TEST_SKIP=1`.
- **`ScansRedisByPrefix` trait.** Shared scan helper that applies the Redis client's built-in prefix to SCAN MATCH patterns (neither predis nor phpredis auto-prefix the MATCH parameter). Used by `RedisPermissionRepository::flushAll`, `TenantAwareRedisPermissionRepository::flushAll`, and `CacheStatsCommand`.
- **`hasAnyRole` / `hasAllRoles` accept a `Collection`,** consistent with `hasRole`. Previously only plain arrays and variadic args were handled.
- **Regression tests for every fix.** 36 new tests covering every issue listed below.

### Fixed

- **`warmPermissionGroups` left stale metadata.** Deletion of a permission in the DB did not remove its entry from the `permission_groups` hash during a rewarm. The hash is now rebuilt atomically via `replacePermissionGroups` (DEL + HSET in MULTI/EXEC).
- **`SeedCommand --fresh` corrupted Redis cache state.** `Role::query()->delete()` and `Permission::query()->delete()` bypassed model events, so the `deleted` hooks that clean up the permission groups hash and the role set keys never ran. Now wrapped in `withoutEvents(...)` with an explicit `flushAll()` afterwards to restore consistency.
- **`warmAll()` and `rewarmAll()` did the same thing.** See Breaking Changes above.
- **`warmUser` / `warmRole` were not atomic.** Permissions and roles were written in two separate MULTI/EXEC transactions; a concurrent reader could observe the new permission set alongside the old role set (or vice versa). Both methods now use `replaceSetBatch` for a single transaction.
- **`PermissionResolver::evictIfNeeded` only triggered when `permissionCache` grew.** Callers that only exercised `getAllPermissions()` could grow `allPermissionsCache` unbounded. The trigger now checks the max size across all per-user caches, and only the exact overflow is evicted.
- **`PermissionResolver::decodeEntry` crashed outside HTTP context.** `auth()->getDefaultDriver()` throws in some queue/console setups. Now guarded with a try/catch and falls back to `config('auth.defaults.guard', 'web')`.
- **Cross-user group staleness in the resolver.** The per-user `permissionGroupsCache` has been removed; group metadata is fetched on every `getAllPermissions()` call (cheap HMGET) so admin-side group edits are immediately visible.
- **`hasAnyRole` / `hasAllRoles` inconsistency with `hasRole`.** Both now accept Collections via `collect(...)->flatten()`.
- **`scopeRole` / `scopePermission` ignored guard when given integer IDs.** Integer IDs are now validated against the target guard before inclusion in the scope.
- **`Gate::before` always resolved to `auth()->getDefaultDriver()`.** `Gate::forUser($apiUser)->allows(...)` now finds the guard whose authenticated user matches the target instance, falling back to the default.
- **`HMSET` (deprecated in Redis 4.0) replaced with `HSET`** multi-field. Field/value pairs are now flattened so predis can serialize them correctly.
- **`CacheStatsCommand` and `flushAll` SCAN MATCH pattern** missed the Redis client's built-in prefix, so on installations where Laravel's `database.redis.options.prefix` is configured (the default `laravel_database_`), no keys matched. The new `ScansRedisByPrefix` trait handles this correctly for both predis and phpredis.
- **`Role` model static `$roleIdNameCache`** is now flushed on role rename and role deletion. Previously stale names could persist within a queue worker's memory.
- **`WithPermissions::seedRoles` threw `Array to string conversion` on numerically-indexed input.** Now validates and throws a clear `InvalidArgumentException`.
- **Migration column lengths pinned to 191** so the `(name, guard_name)` unique index fits within MySQL's 767-byte key limit under utf8mb4 on MySQL < 5.7.7.
- **`ensureUserCacheExists` warning demoted to debug.** In high-traffic deployments with cold caches, the warning produced significant log noise without actionable signal.
- **Middleware OR (`|`) list whitespace trimming** (see Breaking Changes).

### Migration from beta.1

1. If you implement `PermissionRepositoryInterface` in your own code (adapters, test doubles), add `replacePermissionGroups(array $groups): void`.
2. If you relied on `warmAll()` not flushing, switch the call to `rewarmAll()`.
3. If you read `PermissionDTO::$id`, remove that code path (it was always `null`).
4. If you pass role/permission integer IDs via a guard that doesn't own them (cross-guard leakage), expect them to be dropped silently. This is intentional.

## [4.0.0-beta.1] - 2026-04-22

### Breaking Changes

- **`PermissionRepositoryInterface`.** Three new methods added for permission group metadata. Any custom implementation of the interface must implement them:
  - `setPermissionGroups(array $groups): void`
  - `getPermissionGroups(array $encodedNames): array`
  - `deletePermissionGroup(string $encodedName): void`
- **Post-upgrade step required.** After upgrading, run `php artisan permissions-redis:warm --fresh` (or `rewarm`) to populate the new `auth:permission_groups` Redis hash. Until the hash is populated, `getAllPermissions()` will return DTOs with `group: null`.

### Added

- **Permission `group` metadata preserved in Redis.** A new Redis hash (`{prefix}permission_groups`) stores `{guard}|{name}` to `group` mappings. `PermissionResolver::getAllPermissions()` now enriches `PermissionDTO` with the correct group name. Groups are global (not tenant-scoped) because they live in the shared `permissions` table.
- **`Role::hasPermission(string $permission, ?string $guard = null): bool`.** Checks whether a specific role has a permission via Redis (SISMEMBER on the `role:{id}:permissions` set).
- **Guard parameter on Blade directives.** All six directives (`@role`, `@hasanyrole`, `@hasallroles`, `@permission`, `@hasanypermission`, `@hasallpermissions`) now accept an optional second argument for guard override: `@role('admin', 'api')`.
- **`PermissionsAssigned` event.** Dispatched when `givePermissionTo`, `revokePermissionTo`, or `syncPermissions` is called directly on a user. Subscribed by `CacheInvalidator` to trigger rewarming.
- **`DispatchesPermissionEvents` trait.** Opt-in trait for User models that automatically dispatches `UserDeleted` on model deletion. Documented usage replaces manual observer registration.
- **Multiple user models supported.** `user_model` config now accepts an array: `['App\Models\User', 'App\Models\Admin']`. `AuthorizationCacheManager` and the `Gate::before` callback iterate over all configured types.
- **Queue-backed cache warming.** `WarmUserCacheJob` plus `--queue` flag on `permissions-redis:warm` and `permissions-redis:warm-user` commands. Pushes warm operations to the default queue instead of running them synchronously.
- **Guard configuration per seed entry.** `config/permissions-redis.php` `seed` structure now supports `['guard' => 'api']` on roles and permissions. `permissions-redis:seed` also accepts `--guard` as a global override.
- **LRU eviction in `PermissionResolver`.** In-memory resolver caches (`permissionCache`, `roleCache`, etc.) now evict the oldest half once they exceed `resolver_cache_limit` (default `1000`). Prevents unbounded growth in long-running workers.
- **Warm cooldown / rate limiting.** `ensureUserCacheExists` tracks the last warm attempt per user in `warmAttempts` and skips re-warming for `resolver_warm_cooldown` seconds (default `1.0s`). Protects the database from storms if Redis cache creation repeatedly fails.
- **`TransactionFailedException`.** Thrown when Redis `EXEC` returns `null` or `false`, making transaction failures observable instead of silently dropping writes.
- **`AuthorizationCacheManager::rewarmAll()`.** Non-destructive rewarm that writes the new cache before evicting stale keys, eliminating the downtime window present in the old `warmAll()` flow.
- **`RedisPermissionRepository::replaceSetBatch(array $sets): void`.** Pipeline-backed batch method for bulk warming. Used internally by `rewarmAll` to reduce round-trips during bulk operations.
- **`isSuperAdmin` single-call optimization.** Replaces per-guard `SISMEMBER` probes with one `SMEMBERS` followed by in-memory decoding.
- **Role ID cache in `HasRedisPermissions::hasRole`.** Numeric role IDs are resolved via a static map (int to name) instead of one SQL query per check.

### Changed

- **`CacheStatsCommand` regex.** Now matches non-numeric user IDs (UUIDs, ULIDs). Previous `/^user:(\d+):permissions$/` pattern silently excluded them from stats.
- **`SeedCommand` guard handling.** No longer hardcoded to `'web'`. Reads from config per-entry, accepts `--guard=api`, or falls back to the application default guard.
- **`MigrateFromSpatieCommand::ensureTargetTablesExist`.** Returns `bool`; `handle()` halts when it returns `false` instead of proceeding with `copyData()` and producing partial migrations.
- **`TenantAwareRedisPermissionRepository`.** All role-related operations (`setRolePermissions`, `setRoleUsers`, `getRoleUserIds`, `deleteRoleCache`) now apply the tenant prefix. Previously, role data was shared across tenants. `flushAll()` is scoped to the current tenant (`SCAN` with `t:{tenantId}:*`).
- **`PermissionResolver::isSuperAdmin`.** Iterates `loadUserRoles()` once instead of calling `userHasRole` per configured guard.
- **Empty SET sentinel.** The legacy `'__empty__'` marker is replaced by a reserved prefix (`"\x00empty\x00"`) so permissions literally named `__empty__` are no longer filtered out silently.

### Fixed

- **Tenancy isolation leak on roles.** Role cache keys in multi-tenant deployments are now per-tenant, preventing one tenant from reading or mutating another tenant's role/permission pivot data.
- **`flushAll` in multi-tenant deployments.** The decorator no longer clears every tenant's keys; it scans and deletes only the current tenant's prefix space.

### Upgrade Guide

From `v3.0.0` to `v4.0.0-beta.1`:

1. **Run `composer require scabarcas/laravel-permissions-redis:^4.0@beta`**.
2. **If you implement `PermissionRepositoryInterface`** yourself, add the three new methods (`setPermissionGroups`, `getPermissionGroups`, `deletePermissionGroup`). See `RedisPermissionRepository` for a reference implementation.
3. **Run `php artisan permissions-redis:warm --fresh`** to populate the new `auth:permission_groups` Redis hash. Without this, `getAllPermissions()` returns DTOs with `group: null`.
4. **Optional:** Publish the refreshed config (`php artisan vendor:publish --tag=permissions-redis-config`) to pick up the new `resolver_cache_limit`, `resolver_warm_cooldown`, and `queue` options.

## [3.0.0] - 2026-03-31

### Added

- **Multi-tenancy support.** `TenantAwareRedisPermissionRepository` decorator that prefixes all cache keys with the current tenant ID, with built-in Stancl/Tenancy resolver and support for custom resolvers.
- **UUID/ULID support.** Configurable `model_morph_key_type` option (`int`, `uuid`, `ulid`) for non-incrementing primary keys.
- **Octane support.** Optional per-request state reset (`octane.reset_on_request`) that flushes in-memory caches between requests in long-lived workers.
- **`permissions-redis:migrate-from-spatie` command.** Automated migration from `spatie/laravel-permission` with chunked inserts, schema compatibility checks, `--dry-run` mode, and post-migration cache warming.
- **`permissions-redis:seed` command.** Declarative role/permission seeding from config with `--fresh` and `--no-warm` options and production safety confirmation.
- **`WithPermissions` test trait.** Testing helper with `seedPermissions()`, `seedRoles()`, `actingAsWithPermissions()`, `actingAsWithRoles()`, and `flushPermissionCache()` for concise test setup.
- **Documentation.** Migration guide from Spatie with method equivalence tables and behavior differences; integration guide for Policies, Sanctum/Passport, and Laravel Pulse.
- **Community governance.** `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, GitHub issue templates, PR template, and funding configuration.
- **CI improvements.** Redis service in GitHub Actions, test matrix for PHP 8.3/8.4 and Laravel 12/13, coverage reporting, and mutation testing with Infection.
- **Test suite expansion.** Added `OctaneSupportTest`, `TenantAwareRepositoryTest`, and `UuidSupportTest`.

### Fixed

- **Octane reset resolving wrong class.** `registerOctaneReset()` now resolves `RedisPermissionRepository` directly instead of `PermissionRepositoryInterface`, which would fail when multi-tenancy was enabled. Also replaced `::class` reference with a string to avoid undefined class errors when Octane is not installed.

## [2.0.0] - 2026-03-27

### Added

- **Guard-scoped resolution.** All permission and role checks (`hasPermission`, `hasRole`, `getAllPermissions`, `getAllRoles`) now accept an optional `?string $guard` parameter, enabling proper multi-guard support throughout the entire resolution pipeline.
- **Guard-aware Redis storage.** Permissions and roles are stored in Redis as `guard|name` encoded entries, allowing the same permission name to exist under different guards without collision.
- **`forGuard()` fluent method.** Use `$user->forGuard('api')->hasPermissionTo('posts.edit')` to scope a single check to a specific guard.
- **`rewarmAll()` method.** Non-destructive cache rewarm on `AuthorizationCacheManager` that rebuilds without flushing first.
- **`warmPermissionAffectedUsers()` method.** Targeted cache warming for all users affected by a specific permission change.
- **`getUserIdsAffectedByPermission()` method.** Queries all user IDs impacted by a permission (direct + role-inherited).
- **Enum support for roles.** `BackedEnum` values are now fully supported in `assignRole`, `syncRoles`, `removeRole`, and all role-checking methods, matching the existing permission enum support.
- **`PermissionDTO` as `readonly` class.** Immutable data transfer object using PHP 8.2+ `readonly` class syntax.
- **Permission model `deleting` hook.** Automatically cleans up pivot table entries when a permission is deleted.
- **Role model `syncPermissions()`, `givePermissionTo()`, `revokePermissionTo()`.** Fluent permission management methods directly on the Role model with automatic cache invalidation.
- **Test suite expansion.** Added `BladeDirectivesTest`, `RedisPermissionRepositoryTest`, `ModelsTest`, `ServiceProviderTest`, and expanded `HasRedisPermissionsTest`, `CacheManagerWarmTest`, `CommandsTest`, and `PermissionResolverTest` (+1,500 lines of tests).
- **Test enum fixtures.** `TestPermissionEnum` and `TestRoleEnum` for typed enum testing.

### Changed

- **Breaking: `PermissionResolverInterface`.** `hasPermission()`, `hasRole()`, `getAllPermissions()`, and `getAllRoles()` signatures now include an optional `$guard` parameter.
- **Breaking: Redis key format.** Cached entries now use `guard|name` encoding instead of plain names. Existing caches must be flushed and rewarmed after upgrading.
- **Blade directives refactored.** Extracted common authentication logic into `resolveForUser()` helper; all directives now pass the current guard to the resolver.
- **Middleware guard-awareness.** `PermissionMiddleware`, `RoleMiddleware`, and `RoleOrPermissionMiddleware` now resolve the guard from the current authentication driver.
- **Smarter `warmAllUsers()`.** No longer scans the entire users table; queries only users that have at least one role or direct permission assigned.
- **`RedisPermissionRepository` caching.** Connection, prefix, and TTL are now lazily cached in instance properties to avoid repeated config lookups.
- **`UnauthorizedException` improvements.** Cleaner construction with type-safe parameters.

### Fixed

- **Guard isolation.** Previously, permissions assigned under one guard (e.g., `api`) could bleed into checks under another guard (e.g., `web`). All checks are now guard-scoped.

## [1.1.0] - 2026-03-20

### Added

- **Laravel 13 support.** Added `^13.0` constraint to all `illuminate/*` dependencies, making the package fully compatible with Laravel 11, 12, and 13.
- **Orchestra Testbench 11.** Added `^11.0` to dev dependencies for Laravel 13 testing support.

## [1.0.1] - 2026-03-19

### Changed

- **Migration filename.** Renamed to `0000_00_00_000000_create_permission_tables.php` so it loads with a neutral timestamp and does not conflict with application migrations regardless of publish order.
- **Default publish group.** Config and migrations are now also published via `php artisan vendor:publish --provider` (no tag required), in addition to the explicit `permissions-redis-migrations` tag.

## [1.0.0] - 2026-03-19

First stable release of `scabarcas/laravel-permissions-redis`.

### Added

- **Core authorization system.** Eloquent models for `Permission` and `Role` with `findOrCreate` semantics, guard scoping, and polymorphic user relationships across 5 database tables.
- **`HasRedisPermissions` trait.** User model mixin providing the full API: `assignRole`, `syncRoles`, `removeRole`, `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `hasPermissionTo`, `hasAnyPermission`, `hasAllPermissions`, `hasRole`, `hasAnyRole`, `hasAllRoles`, `getAllPermissions`, `getPermissionNames`, `getRoleNames`.
- **Redis-first resolution.** `PermissionResolver` with dual-layer caching (in-memory PHP array + Redis SETs) and automatic cold-start warming from the database.
- **`RedisPermissionRepository`.** Low-level Redis operations using SET data structures (`SISMEMBER`, `SMEMBERS`, `SADD`) with `MULTI/EXEC` atomic transactions and configurable TTL.
- **`AuthorizationCacheManager`.** Cache orchestrator with `warmAll`, `warmUser`, `warmRole`, `evictUser`, and `evictRole` methods for full database-to-Redis synchronization.
- **Automatic cache invalidation.** Event-driven system via `CacheInvalidator` listener handling `RolesAssigned`, `PermissionsSynced`, `RoleDeleted`, and `UserDeleted` events.
- **Cache warming on login.** Optional `WarmCacheOnLogin` listener that automatically warms user permissions on `Illuminate\Auth\Events\Login`.
- **Route middleware.** `permission`, `role`, and `role_or_permission` middleware with support for OR (`|`) and AND (`&`) operators.
- **Blade directives.** `@role`, `@endrole`, `@hasanyrole`, `@endhasanyrole`, `@hasallroles`, `@endhasallroles`, `@permission`, `@endpermission`, `@hasanypermission`, `@endhasanypermission`, `@hasallpermissions`, `@endhasallpermissions`.
- **Laravel Gate integration.** Optional `Gate::before` callback that resolves `$user->can('permission.name')` through Redis.
- **Wildcard permissions.** Optional `fnmatch()` pattern matching (e.g., `users.*` matches `users.create`, `users.edit`).
- **Super admin role.** Configurable role that bypasses all permission checks.
- **Artisan commands.** `permissions-redis:warm`, `permissions-redis:warm-user`, `permissions-redis:flush`, `permissions-redis:stats`.
- **Flexible parameter types.** All assignment and check methods accept `string`, `int` (ID), `BackedEnum`, `array`, or `Collection`.
- **Query scopes.** `User::role('admin')` and `User::permission('posts.edit')` for Eloquent filtering.
- **`PermissionDTO`.** Lightweight immutable data transfer object for permission data.
- **Configurable table names.** All 5 tables can be renamed via config to avoid conflicts.
- **Publishable config.** `config/permissions-redis.php` with env variable support for all runtime options.
- **Database migrations.** Auto-loaded migration for the 5 permission tables with foreign keys and composite primary keys.
- **Test suite.** Unit and integration tests using Pest with `InMemoryPermissionRepository` fixture for testing without Redis.
- **Documentation.** README with installation guide, usage examples, conventions, API reference, and C4 architecture diagrams.

[4.2.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.1.2...v4.2.0
[4.1.2]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.1.1...v4.1.2
[4.1.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.1.0...v4.1.1
[4.1.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.0.1...v4.1.0
[4.0.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.0.0...v4.0.1
[4.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.0.0-beta.2...v4.0.0
[4.0.0-beta.2]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v4.0.0-beta.1...v4.0.0-beta.2
[4.0.0-beta.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v3.0.0...v4.0.0-beta.1
[3.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/scabarcas17/laravel-permissions-redis/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/scabarcas17/laravel-permissions-redis/releases/tag/v1.0.0
