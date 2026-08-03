<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Mode
    |--------------------------------------------------------------------------
    | 'publisher' - This app sends sync events to other apps (subscriber app)
    | 'receiver'  - This app receives sync events from the publisher
    | 'both'      - This app both sends and receives
    */
    'mode' => env('USER_TEAM_SYNC_MODE', 'receiver'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user' => env('USER_TEAM_SYNC_USER_MODEL', 'App\\Models\\User'),
        'team' => env('USER_TEAM_SYNC_TEAM_MODEL', 'Madbox99\\UserTeamSync\\Models\\Team'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher Configuration
    |--------------------------------------------------------------------------
    */
    'publisher' => [
        'api_key' => env('USER_TEAM_SYNC_API_KEY'),

        /*
        |----------------------------------------------------------------------
        | App Source
        |----------------------------------------------------------------------
        | 'config'   - Apps are defined in the 'apps' array below
        | 'database' - Apps are stored in the database (sync_apps table)
        */
        'app_source' => env('USER_TEAM_SYNC_APP_SOURCE', 'config'),
        'apps_table' => 'sync_apps',

        'apps' => [
            // 'crm' => [
            //     'url' => env('CRM_APP_URL'),
            //     'api_key' => env('CRM_APP_API_KEY'),
            //     'active' => true,
            // ],
        ],

        'queue' => env('USER_TEAM_SYNC_QUEUE', 'default'),
        'connection' => env('USER_TEAM_SYNC_QUEUE_CONNECTION'),
        'tries' => env('USER_TEAM_SYNC_TRIES', 3),
        'backoff' => env('USER_TEAM_SYNC_BACKOFF', 60),
        'timeout' => env('USER_TEAM_SYNC_TIMEOUT', 10),

        'auto_observe' => true,
        'sync_fields' => ['email', 'role'],

        /*
        |----------------------------------------------------------------------
        | Team Sync Fields
        |----------------------------------------------------------------------
        | Team fields whose change is propagated to receivers. 'slug' matters
        | most: receivers used to match teams by slug forever after creation, so
        | a rename on the publisher silently broke the cross-app link.
        */
        'team_sync_fields' => ['name', 'slug'],
        'skip_ssl_for_test_domains' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Receiver Configuration
    |--------------------------------------------------------------------------
    */
    'receiver' => [
        'api_key' => env('USER_TEAM_SYNC_API_KEY'),
        'route_prefix' => 'api',
        'middleware' => [],
        'role_driver' => 'spatie',
        'default_role' => 'subscriber',
        'default_active' => false,
        'inactive_redirect_url' => env('USER_TEAM_SYNC_INACTIVE_REDIRECT_URL'),

        /*
        |----------------------------------------------------------------------
        | Bypass Route Patterns
        |----------------------------------------------------------------------
        | Route name patterns that the EnsureUserHasActiveSubscription
        | middleware allows through regardless of subscription status.
        | Defaults cover Filament panel logout and a generic 'logout' route
        | so inactive users can always sign out.
        */
        'bypass_route_patterns' => [
            'filament.*.auth.logout',
            'logout',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => true,
        'table' => 'sync_logs',
        'retention_days' => 30,
    ],
];
