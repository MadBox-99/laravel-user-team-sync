# Laravel User Team Sync

Laravel package for synchronizing users and teams across multiple Laravel applications using a Publisher/Receiver pattern.

## Requirements

- PHP 8.3+
- Laravel 11 or 12

## Installation

```bash
composer require madbox-99/laravel-user-team-sync
```

Then run the install command:

```bash
php artisan user-team-sync:install
```

This will:
- Ask for your sync mode (publisher/receiver/both)
- Generate or accept an API key
- Update your `.env` file
- Publish the config and migration files
- Optionally run migrations

### Manual Installation

If you prefer to install manually:

```bash
php artisan vendor:publish --tag=user-team-sync-config
php artisan vendor:publish --tag=user-team-sync-migrations
php artisan migrate
```

Then add these to your `.env`:

```env
USER_TEAM_SYNC_MODE=receiver
USER_TEAM_SYNC_API_KEY=your-secret-key
```

## Configuration

### Modes

| Mode | Description |
|------|-------------|
| `publisher` | Sends sync events to other apps |
| `receiver` | Receives sync events from a publisher |
| `both` | Sends and receives |
| `client` | Delegates authentication to a central identity provider instead of receiving pushed user/team data |

### Publisher Setup

Set mode to `publisher`. Receiver apps can be stored in the **config file** or in the **database**.

#### Option A: Config-based apps (default)

Define apps in `config/user-team-sync.php`:

```php
'publisher' => [
    'app_source' => 'config', // default
    'api_key' => env('USER_TEAM_SYNC_API_KEY'),
    'apps' => [
        'crm' => [
            'url' => env('CRM_APP_URL'),
            'api_key' => env('CRM_APP_API_KEY'), // optional, falls back to default
            'active' => true,
        ],
        'shop' => [
            'url' => env('SHOP_APP_URL'),
            'api_key' => env('SHOP_APP_API_KEY'),
            'active' => true,
        ],
    ],
],
```

#### Option B: Database-based apps (recommended)

Store apps in the `sync_apps` table for dynamic management:

```env
USER_TEAM_SYNC_APP_SOURCE=database
```

The install command (`php artisan user-team-sync:install`) can set this up interactively, or you can manage apps manually:

```php
use Madbox99\UserTeamSync\Models\SyncApp;

// Add a new app
SyncApp::create([
    'name' => 'crm',
    'url' => 'https://crm.example.com',
    'api_key' => 'secret-key', // stored encrypted
    'is_active' => true,
]);

// Deactivate an app
SyncApp::where('name', 'crm')->update(['is_active' => false]);
```

> API keys are stored using Laravel's `encrypted` cast. Make sure your `APP_KEY` is set.

### Receiver Setup

Set mode to `receiver` and configure the API key:

```php
'receiver' => [
    'api_key' => env('USER_TEAM_SYNC_API_KEY'),
    'route_prefix' => 'api',
    'role_driver' => 'spatie',
    'default_role' => 'subscriber',
    'default_active' => false,
],
```

> The receiver API key must match the publisher's key for that app.

## Client mode (SSO)

Set mode to `client` to stop receiving pushed user/team data and instead authenticate
users against a central Laravel Passport identity provider, rebuilding this app's
local state from the token's claims on every login and on every revalidation.

```env
USER_TEAM_SYNC_MODE=client

IDENTITY_APP_KEY=crm
IDENTITY_URL=https://your-identity-provider.example
IDENTITY_CLIENT_ID=your-oauth-client-id
IDENTITY_CLIENT_SECRET=your-oauth-client-secret
IDENTITY_REDIRECT_URI=https://this-app.example/auth/callback

IDENTITY_REVALIDATE_MINUTES=15
IDENTITY_GRACE_HOURS=24
IDENTITY_RETRY_MINUTES=5
```

```php
'client' => [
    // This app's own key. Must equal sync_apps.name on the publisher and the
    // slug of the plan category that grants access to this app. A login is
    // refused when this key is absent from the token's `apps` claim.
    'app_key' => env('IDENTITY_APP_KEY'),

    'identity_url' => env('IDENTITY_URL'),
    'client_id' => env('IDENTITY_CLIENT_ID'),
    'client_secret' => env('IDENTITY_CLIENT_SECRET'),
    'redirect_uri' => env('IDENTITY_REDIRECT_URI'),
    'scopes' => '',
    'http_timeout' => env('IDENTITY_HTTP_TIMEOUT', 10),

    // Kept short: the revalidation middleware runs on every authenticated
    // page, so a hanging connect would otherwise pin a worker and the
    // session lock for the full read timeout, request after request.
    'http_connect_timeout' => env('IDENTITY_HTTP_CONNECT_TIMEOUT', 3),

    // Re-fetch the claims once the session's last check is older than this.
    'revalidate_after_minutes' => env('IDENTITY_REVALIDATE_MINUTES', 15),

    // How long a session survives while the identity provider is unreachable.
    'grace_hours' => env('IDENTITY_GRACE_HOURS', 24),

    // How long to wait before retrying an unreachable provider. Without it
    // every request would retry, so a slow provider would cost every page
    // load the full HTTP timeout for the whole grace window.
    'retry_after_minutes' => env('IDENTITY_RETRY_MINUTES', 5),

    // Comma-separated e-mails. Non-empty during a phased rollout: only these
    // users go through SSO, everyone else keeps using the legacy login and
    // the legacy push. Empty means everyone goes through SSO.
    'allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTITY_SSO_ALLOWLIST', ''))))),

    // Keeps the legacy receiver endpoints mounted while both worlds run
    // side by side. Turn off once the rollout is complete.
    'legacy_receiver' => env('IDENTITY_LEGACY_RECEIVER', true),

    // Maps a token role name onto a local role name; leave empty to rely on
    // the case-insensitive fallback in IdentityProvisioner.
    'role_map' => [],

    // Where to send a user who authenticated but has no subscription
    // covering this app.
    'subscribe_url' => env('IDENTITY_SUBSCRIBE_URL'),
],
```

### Routes

| Method | Route | Name | Purpose |
|--------|-------|------|---------|
| GET | `/auth/redirect` | `identity.redirect` | Starts the OAuth authorization-code + PKCE handshake with the identity provider |
| GET | `/auth/callback` | `identity.callback` | Exchanges the code for tokens, fetches claims, provisions the local user, and signs them in |

Point your login link at `route('identity.redirect')` (it accepts an optional `?intended=` relative
path to return to after login). Both routes are registered automatically under the `web` middleware
group when `mode` is `client` — nothing else to add to `routes/web.php`.

### Keeping sessions self-healing: `RevalidateIdentity`

Signing in only provisions the user once. To pick up a team rename, a new membership, a role
change or a cancelled subscription without any push from the publisher, add
`Madbox99\UserTeamSync\Client\Http\Middleware\RevalidateIdentity` to your app's authenticated
middleware stack — for a Filament panel, its `authMiddleware()`:

```php
->authMiddleware([
    Authenticate::class,
    \Madbox99\UserTeamSync\Client\Http\Middleware\RevalidateIdentity::class,
])
```

On every request past a fresh `CHECKED_AT` (older than `revalidate_after_minutes`), the middleware:

1. Re-fetches the claims from `/api/userinfo`. A `401` first retries once with the refresh token —
   an aged-out access token is not the same thing as revoked access — and only logs the user out if
   the refresh also fails.
2. Re-runs `IdentityProvisioner`, so a renamed team, an added/removed membership or a role change
   lands locally within one `revalidate_after_minutes` window.
3. Logs the user out if this app's `app_key` has disappeared from the token's `apps` claim — this is
   how a cancelled subscription takes effect fleet-wide with no push.
4. Treats a `5xx`/unreachable provider — or a 2xx whose body is not a well-formed claims payload,
   such as a maintenance page served mid-deploy — as an **outage**, not as revoked access: the
   session survives for up to `grace_hours` and only logs out once the grace window is exhausted.
   Collapsing this distinction would turn a five-minute identity-provider outage into a forced
   logout across every app in the fleet.
5. Retries a failing provider at most once per `retry_after_minutes` rather than on every request,
   so a *slow* provider does not add its timeout to every page load for every user. The grace
   window still runs from the **first** failure, so a long outage expires on schedule.
6. Never lets an unexpected error escape: anything other than a deliberate rejection or an
   unreconcilable conflict is logged and tolerated rather than 500-ing the page.

> The middleware only touches sessions that were established through SSO. During a phased rollout
> (see `allowlist`) the same app still signs other users in through the ordinary password form;
> those sessions carry no identity token and are passed through untouched.

> **SSO logins deliberately mint no "remember me" cookie.** A recaller cookie outlives the session
> and would re-authenticate the user into a fresh session holding no identity state, which the
> middleware passes through by design — permanently exempting anyone who idles past
> `SESSION_LIFETIME` from revalidation. After the session lapses the user is sent back through the
> identity provider instead, which for an already-signed-in user is a transparent round trip.

#### Operational note: malformed claims count as an outage

A 2xx response whose body is not a well-formed claims payload is treated exactly like an
unreachable provider, **not** like a bad request. That covers a maintenance or proxy error page
served with HTTP 200, but also a genuine data-quality fault on the provider side: an `orgs` entry
with an empty `slug` or `name`, or a user with an empty `name`.

What that looks like in practice: affected sessions keep working for `grace_hours` and then log out
silently, instead of the page erroring loudly. The trade is deliberate — a fleet-wide 500 on every
page of every module app is the worse failure — but it means a provider-side data fault is
**quiet**, so watch the logs rather than waiting for user reports:

- `user-team-sync: grace period expired while the identity provider was unreachable` — sessions are
  now being logged out; the `reason` field carries the provider-side symptom.
- `user-team-sync: unexpected failure during identity revalidation` — a bug rather than an outage;
  logged with the exception class and throw site only. Claims, tokens and exception messages are
  never logged, because a `QueryException` interpolates its bindings and would leak personal data.

## Usage

### Automatic Sync (Observer)

When `auto_observe` is enabled (default), the package automatically watches your User model for changes to configured fields (`email`, `role` by default) and syncs them to all active receiver apps.

```php
// This automatically triggers sync to all receiver apps
$user->update(['role' => 'admin']);
```

### Manual Sync via Facade

```php
use Madbox99\UserTeamSync\Facades\UserTeamSync;

// Create a user on all receiver apps
UserTeamSync::createUser(
    email: 'john@example.com',
    name: 'John Doe',
    password: 'plain-text-password', // hashed automatically before sending
    role: 'editor',
    ownerEmail: 'owner@example.com',
);

// Sync user changes
UserTeamSync::syncUser('john@example.com', [
    'new_email' => 'john.doe@example.com',
    'role' => 'admin',
]);

// Create a team on all receiver apps
UserTeamSync::createTeam(
    teamName: 'Marketing',
    userEmail: 'john@example.com',
    slug: 'marketing', // optional, auto-generated from name
);

// Toggle user active status on a specific app
UserTeamSync::toggleUserActive(
    userEmail: 'john@example.com',
    isActive: true,
    appKey: 'crm',
);
```

## API Endpoints (Receiver)

All endpoints are protected by Bearer token authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/create-user` | Create a new user |
| POST | `/api/sync-user` | Update user fields |
| POST | `/api/toggle-user-active` | Set active/inactive status |
| POST | `/api/create-team` | Create a team |
| POST | `/api/update-team` | Apply a team rename (see below) |
| GET | `/api/user-teams` | Get user's teams |
| POST | `/api/sync-password` | Sync password hash |
| GET | `/api/identity-audit` | Diff users/teams/memberships against the publisher |
| POST | `/api/identity-uuids` | Bulk-apply the publisher's uuid mapping |

### Team renames

Receivers used to match teams by slug forever after creation, so renaming a team
on the publisher silently and permanently broke the cross-app link. `TeamSyncObserver`
now propagates changes to the fields in `publisher.team_sync_fields` (default
`name`, `slug`) via `UpdateTeamJob`.

`/api/update-team` identifies the team by `uuid`. It falls back to `original_slug`
— the team's *pre-rename* slug, which is what the receiver still knows it by —
only when the local team has no uuid of its own, and then adopts the publisher's
uuid so the next rename skips the fallback. A local uuid that differs from the
incoming one means the two sides disagree about which team this is; that returns
`409` rather than renaming the wrong team.

A `404` is normal, not an error: the publisher fans out to every active app
regardless of entitlement, so most apps do not know most teams. Those are logged
with status `skipped`.

## Events

Listen to these events for custom logic:

| Event | When |
|-------|------|
| `UserCreatedFromSync` | User created on receiver |
| `UserSynced` | User fields updated |
| `PasswordSynced` | Password synced to receiver |
| `UserActiveToggled` | Active status changed |
| `TeamCreatedFromSync` | Team created on receiver |
| `TeamUpdatedFromSync` | Team renamed on receiver |
| `TeamSynced` | A receiver accepted a team change |
| `TeamSyncFailed` | A receiver rejected a team change (a 404 does not count) |
| `SyncFailed` | Any user sync operation failed |

```php
// In EventServiceProvider or listener
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;

class HandleSyncedUser
{
    public function handle(UserCreatedFromSync $event): void
    {
        // $event->user
    }
}
```

## Logging

All sync operations are logged to the `sync_logs` table. Configure in `config/user-team-sync.php`:

```php
'logging' => [
    'enabled' => true,
    'table' => 'sync_logs',
    'retention_days' => 30,
],
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `USER_TEAM_SYNC_MODE` | Sync mode | `receiver` |
| `USER_TEAM_SYNC_API_KEY` | API key for authentication | — |
| `USER_TEAM_SYNC_APP_SOURCE` | App storage: `config` or `database` | `config` |
| `USER_TEAM_SYNC_USER_MODEL` | User model class | `App\Models\User` |
| `USER_TEAM_SYNC_TEAM_MODEL` | Team model class | `App\Models\Team` |
| `USER_TEAM_SYNC_QUEUE` | Queue name for jobs | `default` |
| `USER_TEAM_SYNC_QUEUE_CONNECTION` | Queue connection | `null` |
| `USER_TEAM_SYNC_TRIES` | Job retry attempts | `3` |
| `USER_TEAM_SYNC_BACKOFF` | Seconds between retries | `60` |
| `USER_TEAM_SYNC_TIMEOUT` | HTTP timeout in seconds | `10` |
| `IDENTITY_APP_KEY` | This app's key in the `apps` claim (client mode) | — |
| `IDENTITY_URL` | Identity provider base URL (client mode) | `https://cegem360.eu` |
| `IDENTITY_CLIENT_ID` | OAuth client ID (client mode) | — |
| `IDENTITY_CLIENT_SECRET` | OAuth client secret (client mode) | — |
| `IDENTITY_REDIRECT_URI` | OAuth redirect URI, must match `/auth/callback` (client mode) | — |
| `IDENTITY_HTTP_TIMEOUT` | HTTP timeout in seconds for identity provider calls | `10` |
| `IDENTITY_REVALIDATE_MINUTES` | Minutes before `RevalidateIdentity` re-checks the session | `15` |
| `IDENTITY_GRACE_HOURS` | Hours a session survives while the identity provider is unreachable | `24` |
| `IDENTITY_SSO_ALLOWLIST` | Comma-separated e-mails allowed through SSO during a phased rollout | — (everyone) |
| `IDENTITY_LEGACY_RECEIVER` | Keep legacy receiver endpoints mounted alongside client mode | `true` |
| `IDENTITY_SUBSCRIBE_URL` | Where to send an authenticated user with no entitlement for this app | `https://cegem360.eu` |

## Testing

```bash
vendor/bin/pest
```

## License

MIT License. See [LICENSE](LICENSE) for details.
