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
        'team' => env('USER_TEAM_SYNC_TEAM_MODEL', 'App\\Models\\Team'),
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
