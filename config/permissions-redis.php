<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection name used for storing authorization data.
    | This should match a connection defined in your config/database.php
    | under the 'redis.connections' key.
    |
    */

    'redis_connection' => env('PERMISSIONS_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all Redis keys managed by this package.
    |
    */

    'prefix' => env('PERMISSIONS_REDIS_PREFIX', 'auth:'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Time-to-live for cached permission and role data in Redis.
    | Default: 86400 (24 hours).
    |
    */

    'ttl' => (int) env('PERMISSIONS_REDIS_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of your application's User model.
    | Used for morph type resolution and Gate integration.
    |
    */

    'user_model' => env('PERMISSIONS_REDIS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel used for authorization-related log messages.
    | Set to null to use the default log channel.
    |
    */

    'log_channel' => env('PERMISSIONS_REDIS_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Register Gate
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers a Gate::before callback so that
    | $user->can('permission.name') resolves permissions from Redis.
    |
    */

    'register_gate' => true,

    /*
    |--------------------------------------------------------------------------
    | Register Middleware
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers 'permission', 'role', and
    | 'role_or_permission' middleware aliases automatically.
    |
    */

    'register_middleware' => true,

    /*
    |--------------------------------------------------------------------------
    | Warm Cache on Login
    |--------------------------------------------------------------------------
    |
    | When enabled, the package listens to Illuminate\Auth\Events\Login
    | and automatically warms the permission cache for the logged-in user.
    |
    */

    'warm_on_login' => true,

    /*
    |--------------------------------------------------------------------------
    | Super Admin Role
    |--------------------------------------------------------------------------
    |
    | Users with this role will bypass all permission checks, returning true
    | for every hasPermission() call. Set to null to disable.
    |
    */

    'super_admin_role' => env('PERMISSIONS_REDIS_SUPER_ADMIN_ROLE', null),

    /*
    |--------------------------------------------------------------------------
    | Wildcard Permissions
    |--------------------------------------------------------------------------
    |
    | When enabled, permissions support wildcard patterns using fnmatch().
    | For example, 'users.*' will match 'users.create', 'users.edit', etc.
    |
    */

    'wildcard_permissions' => (bool) env('PERMISSIONS_REDIS_WILDCARD', false),

    /*
    |--------------------------------------------------------------------------
    | Register Blade Directives
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers Blade directives:
    | @role, @hasanyrole, @hasallroles, @permission, @hasanypermission,
    | @hasallpermissions — all resolved through Redis.
    |
    */

    'register_blade_directives' => true,

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by this package to avoid conflicts
    | with existing tables in your application.
    |
    */

    'tables' => [
        'permissions'           => 'permissions',
        'roles'                 => 'roles',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ],

];
