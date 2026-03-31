# Integrations

This guide shows how to combine `laravel-permissions-redis` with other Laravel packages and patterns.

---

## Table of Contents

- [Laravel Policies](#laravel-policies)
- [Laravel Sanctum / Passport](#laravel-sanctum--passport)
- [Laravel Pulse](#laravel-pulse)

---

## Laravel Policies

Laravel Policies work seamlessly with this package thanks to the Gate integration. When `register_gate` is enabled (default), `$user->can('ability')` resolves through Redis automatically.

### How it works

1. You call `$user->can('posts.edit')` or `$this->authorize('posts.edit')` in a controller
2. Laravel's Gate fires a `before` callback registered by this package
3. The callback checks Redis via `PermissionResolver::hasPermission()`
4. If the user has the permission, the Gate returns `true` — the Policy method is never called
5. If the user does **not** have the permission, the Gate falls through to the Policy method

This means you can use **both** Redis-backed permissions and Policies. Redis permissions act as a fast-pass: if the permission exists, it's granted immediately. If not, the Policy gets a chance to decide.

### Example: PostPolicy

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Gate::before (Redis) checks 'posts.view' first.
     * This method is only called if the user does NOT have 'posts.view' in Redis.
     */
    public function view(User $user, Post $post): bool
    {
        // Fallback: allow users to view their own posts
        return $post->user_id === $user->id;
    }

    /**
     * Gate::before checks 'posts.edit' first.
     * Fallback: allow post owners to edit.
     */
    public function update(User $user, Post $post): bool
    {
        return $post->user_id === $user->id;
    }

    /**
     * Gate::before checks 'posts.delete' first.
     * No fallback — only users with explicit permission can delete.
     */
    public function delete(User $user, Post $post): bool
    {
        return false;
    }

    /**
     * Gate::before checks 'posts.create' first.
     * Fallback: check a custom condition.
     */
    public function create(User $user): bool
    {
        return $user->email_verified_at !== null;
    }
}
```

### Using it in controllers

```php
class PostController extends Controller
{
    public function edit(Post $post)
    {
        // Checks Redis permission first, then falls through to PostPolicy::update()
        $this->authorize('posts.edit', $post);

        return view('posts.edit', compact('post'));
    }

    public function store(Request $request)
    {
        $this->authorize('posts.create');

        // ...
    }
}
```

### Using it in Blade

```blade
@can('posts.edit', $post)
    <a href="{{ route('posts.edit', $post) }}">Edit</a>
@endcan

@can('posts.delete', $post)
    <button>Delete</button>
@endcan
```

### Policy auto-discovery

Laravel auto-discovers policies by convention (`App\Policies\PostPolicy` for `App\Models\Post`). No extra registration is needed. The Gate `before` callback from this package runs before any Policy, so:

| User has Redis permission? | Policy method called? | Result |
|:-:|:-:|---|
| Yes | No | Granted (fast path via Redis) |
| No | Yes | Policy decides |

### Explicit permission names in Policies

If your permission names don't match the Policy ability names, you can use `hasPermissionTo()` directly inside the Policy:

```php
public function update(User $user, Post $post): bool
{
    // Check a more specific permission
    if ($user->hasPermissionTo('posts.edit.published') && $post->is_published) {
        return true;
    }

    return $post->user_id === $user->id;
}
```

### Testing Policies with WithPermissions

```php
use Scabarcas\LaravelPermissionsRedis\Testing\WithPermissions;

class PostPolicyTest extends TestCase
{
    use WithPermissions;

    public function test_user_with_permission_can_edit_any_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(); // belongs to another user

        $this->actingAsWithPermissions($user, ['posts.edit'])
            ->get("/posts/{$post->id}/edit")
            ->assertOk();
    }

    public function test_user_without_permission_can_edit_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        // No Redis permission — Policy fallback allows own posts
        $this->actingAs($user)
            ->get("/posts/{$post->id}/edit")
            ->assertOk();
    }

    public function test_user_without_permission_cannot_edit_others_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(); // belongs to another user

        // No Redis permission + not the owner → denied
        $this->actingAs($user)
            ->get("/posts/{$post->id}/edit")
            ->assertForbidden();
    }
}
```

#### Pest example

```php
uses(WithPermissions::class);

it('grants access via Redis permission', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    $this->actingAsWithPermissions($user, ['posts.edit'])
        ->get("/posts/{$post->id}/edit")
        ->assertOk();
});

it('falls through to Policy when no Redis permission', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();

    $this->actingAs($user)
        ->get("/posts/{$post->id}/edit")
        ->assertOk(); // Policy allows own posts
});
```

---

## Laravel Sanctum / Passport

When building APIs with [Sanctum](https://laravel.com/docs/sanctum) or [Passport](https://laravel.com/docs/passport), you often need to check **both** the token's abilities/scopes **and** the user's Redis permissions.

### Pattern: Token ability + user permission

The recommended pattern is to check both layers:

1. **Token layer** — Does this API token have the ability to perform this action?
2. **User layer** — Does the user behind this token have the permission?

```php
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/posts', [PostController::class, 'store'])
        ->middleware('permission:posts.create');
});
```

With this setup:
- Sanctum validates the token and authenticates the user
- The `permission` middleware checks the user's Redis permissions

### Combining token abilities with permissions

If you want to enforce **both** token scopes and user permissions:

```php
class PostController extends Controller
{
    public function store(Request $request)
    {
        // 1. Check token ability (Sanctum)
        if ($request->user()->tokenCant('posts:create')) {
            abort(403, 'Token does not have the posts:create ability.');
        }

        // 2. Check user permission (Redis) — already handled by middleware
        //    or check manually:
        if (!$request->user()->hasPermissionTo('posts.create')) {
            abort(403, 'User does not have the posts.create permission.');
        }

        // Both checks passed
        return Post::create($request->validated());
    }
}
```

### Custom middleware for dual checks

For routes that require both token abilities and user permissions, create a middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenAndPermission
{
    public function handle(Request $request, Closure $next, string $ability): mixed
    {
        $user = $request->user();

        // Check Sanctum token ability (using colon notation)
        if ($user?->currentAccessToken() && $user->tokenCant($ability)) {
            abort(403, "Token lacks the '{$ability}' ability.");
        }

        // Check user permission (using dot notation)
        $permission = str_replace(':', '.', $ability);
        if (!$user?->hasPermissionTo($permission)) {
            abort(403, "User lacks the '{$permission}' permission.");
        }

        return $next($request);
    }
}
```

Register it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'ability' => \App\Http\Middleware\EnsureTokenAndPermission::class,
    ]);
})
```

Use it in routes:

```php
// Token must have 'posts:create' ability AND user must have 'posts.create' permission
Route::post('/posts', [PostController::class, 'store'])
    ->middleware(['auth:sanctum', 'ability:posts:create']);

Route::delete('/posts/{post}', [PostController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'ability:posts:delete']);
```

### Guard configuration for API routes

When using an `api` guard, create permissions and roles for that guard:

```php
// Create API-scoped permissions
Permission::findOrCreate('posts.create', 'api');
Permission::findOrCreate('posts.edit', 'api');

// Create API-scoped roles
$apiAdmin = Role::findOrCreate('api_admin', 'api');
$apiAdmin->syncPermissions(['posts.create', 'posts.edit']);

// Assign to user
$user->forGuard('api')->assignRole('api_admin');
```

Check permissions with the correct guard:

```php
// In API routes (guard auto-detected from auth config)
$user->hasPermissionTo('posts.create'); // uses default guard

// Or explicitly
$user->forGuard('api')->hasPermissionTo('posts.create');
```

### Token creation with abilities

When issuing Sanctum tokens, mirror the user's permissions as token abilities:

```php
public function createToken(Request $request)
{
    $user = $request->user();

    // Issue token with abilities matching user's permissions
    $abilities = $user->getPermissionNames()
        ->map(fn (string $perm) => str_replace('.', ':', $perm))
        ->all();

    $token = $user->createToken('api-token', $abilities);

    return ['token' => $token->plainTextToken];
}
```

---

## Laravel Pulse

[Laravel Pulse](https://laravel.com/docs/pulse) provides real-time application monitoring. You can track permission check performance by creating a custom recorder.

> **Note:** This integration is optional and does not require Pulse as a dependency. Install Pulse separately if you want this feature.

### Setup

#### 1. Install Pulse

```bash
composer require laravel/pulse
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

#### 2. Create the recorder

```php
<?php

namespace App\Pulse;

use Illuminate\Config\Repository;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns\Sampling;

class PermissionCheckRecorder
{
    use Sampling;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [];

    public function __construct(
        public Pulse $pulse,
        public Repository $config,
    ) {
    }

    /**
     * Record a permission check.
     */
    public function record(string $type, string $key, float $durationMs): void
    {
        if (!$this->shouldSample()) {
            return;
        }

        $this->pulse->record(
            type: 'permission_check',
            key: "{$type}:{$key}",
            value: (int) round($durationMs * 1000), // microseconds
        )->avg()->onlyBuckets();
    }
}
```

#### 3. Extend the PermissionResolver

Create a decorator that records timing data:

```php
<?php

namespace App\Providers;

use App\Pulse\PermissionCheckRecorder;
use Illuminate\Support\Collection;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;

class InstrumentedPermissionResolver implements PermissionResolverInterface
{
    public function __construct(
        private readonly PermissionResolverInterface $inner,
        private readonly PermissionCheckRecorder $recorder,
    ) {
    }

    public function hasPermission(int|string $userId, string $permission, ?string $guard = null): bool
    {
        $start = hrtime(true);
        $result = $this->inner->hasPermission($userId, $permission, $guard);
        $duration = (hrtime(true) - $start) / 1e6; // ms

        $this->recorder->record('permission', $permission, $duration);

        return $result;
    }

    public function hasRole(int|string $userId, string $role, ?string $guard = null): bool
    {
        $start = hrtime(true);
        $result = $this->inner->hasRole($userId, $role, $guard);
        $duration = (hrtime(true) - $start) / 1e6;

        $this->recorder->record('role', $role, $duration);

        return $result;
    }

    public function getAllPermissions(int|string $userId, ?string $guard = null): Collection
    {
        return $this->inner->getAllPermissions($userId, $guard);
    }

    public function getAllRoles(int|string $userId, ?string $guard = null): Collection
    {
        return $this->inner->getAllRoles($userId, $guard);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function flushUser(int|string $userId): void
    {
        $this->inner->flushUser($userId);
    }
}
```

#### 4. Register the decorator

In your `AppServiceProvider`:

```php
use App\Providers\InstrumentedPermissionResolver;
use App\Pulse\PermissionCheckRecorder;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionResolverInterface;
use Scabarcas\LaravelPermissionsRedis\Resolver\PermissionResolver;

public function register(): void
{
    if (class_exists(\Laravel\Pulse\Pulse::class)) {
        $this->app->singleton(PermissionCheckRecorder::class);

        $this->app->extend(PermissionResolverInterface::class, function ($resolver, $app) {
            return new InstrumentedPermissionResolver(
                $resolver,
                $app->make(PermissionCheckRecorder::class),
            );
        });
    }
}
```

#### 5. Create a Pulse card (optional)

Create a Livewire component to display permission check metrics on your Pulse dashboard:

```php
<?php

namespace App\Livewire\Pulse;

use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class PermissionChecks extends Card
{
    public function render()
    {
        [$checks, $time, $runAt] = $this->remember(fn () => [
            $this->aggregate('permission_check', 'count'),
            $this->aggregate('permission_check', 'avg'),
            now(),
        ]);

        return view('livewire.pulse.permission-checks', [
            'checks' => $checks,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
```

Add the card to your `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:pulse.permission-checks cols="4" />
```

### What you can monitor

| Metric | Description |
|--------|-------------|
| Total checks | Number of permission/role checks per period |
| Avg latency | Average time per check (should be <1ms with warm cache) |
| Top permissions | Most frequently checked permissions |
| Top roles | Most frequently checked roles |

This helps identify:
- Permission checks that happen too frequently (candidate for caching at app level)
- Unexpected latency spikes (Redis connection issues)
- Unused permissions (never checked in production)
