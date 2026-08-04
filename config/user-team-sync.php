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
    | 'client'    - This app delegates authentication to the identity
    |                provider instead of receiving pushed user/team data
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
    | Client Configuration
    |--------------------------------------------------------------------------
    | Used when mode is 'client': this app delegates authentication to the
    | identity provider and rebuilds its local user state from the token
    | claims on every login and every revalidation.
    */
    'client' => [
        /*
        | This app's own key. Must equal sync_apps.name on the publisher and
        | the slug of the plan category that grants access to this app. The
        | callback rejects the login when this key is absent from the token's
        | 'apps' claim.
        */
        'app_key' => env('IDENTITY_APP_KEY'),

        'identity_url' => env('IDENTITY_URL', 'https://cegem360.eu'),
        'client_id' => env('IDENTITY_CLIENT_ID'),
        'client_secret' => env('IDENTITY_CLIENT_SECRET'),
        'redirect_uri' => env('IDENTITY_REDIRECT_URI'),
        'scopes' => '',

        'http_timeout' => env('IDENTITY_HTTP_TIMEOUT', 10),

        /*
        | Kept short on purpose. The revalidation middleware runs on every
        | authenticated page, so a provider whose TCP connect hangs would
        | otherwise pin a PHP-FPM worker and the session lock for the full
        | read timeout, on request after request.
        */
        'http_connect_timeout' => env('IDENTITY_HTTP_CONNECT_TIMEOUT', 3),

        /*
        | Re-fetch the claims and re-run the provisioner when the session's
        | last check is older than this. This is what makes a team rename, a
        | new membership or a cancelled subscription reach the app without any
        | push from the publisher.
        */
        'revalidate_after_minutes' => env('IDENTITY_REVALIDATE_MINUTES', 15),

        /*
        | How long a session survives while the identity provider is
        | unreachable. An outage is not the same thing as revoked access: an
        | already-working user keeps working, only new logins are blocked.
        */
        'grace_hours' => env('IDENTITY_GRACE_HOURS', 24),

        /*
        | How long to wait before trying the identity provider again while it
        | is unreachable. Without this every single request would retry, so a
        | hanging provider would add the full HTTP timeout to every page load
        | of every user for the whole grace window. The grace window itself
        | still runs from the first failure, so a long outage expires.
        */
        'retry_after_minutes' => env('IDENTITY_RETRY_MINUTES', 5),

        /*
        | Transitional, phase 3 only. Comma-separated e-mail addresses. When
        | non-empty, only these users may sign in through SSO; everyone else
        | keeps using the legacy login form and the legacy push. Empty means
        | everyone goes through SSO.
        */
        'allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IDENTITY_SSO_ALLOWLIST', '')),
        ))),

        /*
        | Transitional, phase 3 only. Keeps the legacy receiver endpoints
        | mounted while both worlds run side by side.
        */
        'legacy_receiver' => env('IDENTITY_LEGACY_RECEIVER', true),

        /*
        | Maps a role name from the token onto a local role name. The publisher
        | sends lower-case values ('admin', 'manager', 'subscriber') while a
        | receiver may name its roles differently ('Manager'). Leave empty to
        | rely on the case-insensitive fallback in IdentityProvisioner.
        */
        'role_map' => [],

        /*
        | Where to send a user who authenticated successfully but has no
        | subscription covering this app.
        */
        'subscribe_url' => env('IDENTITY_SUBSCRIBE_URL', 'https://cegem360.eu'),
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
