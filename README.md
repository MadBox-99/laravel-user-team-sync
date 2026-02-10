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

### Publisher Setup

Set mode to `publisher` and configure your receiver apps in `config/user-team-sync.php`:

```php
'publisher' => [
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
| GET | `/api/user-teams` | Get user's teams |
| POST | `/api/sync-password` | Sync password hash |

## Events

Listen to these events for custom logic:

| Event | When |
|-------|------|
| `UserCreatedFromSync` | User created on receiver |
| `UserSynced` | User fields updated |
| `PasswordSynced` | Password synced to receiver |
| `UserActiveToggled` | Active status changed |
| `TeamCreatedFromSync` | Team created on receiver |
| `SyncFailed` | Any sync operation failed |

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
| `USER_TEAM_SYNC_USER_MODEL` | User model class | `App\Models\User` |
| `USER_TEAM_SYNC_TEAM_MODEL` | Team model class | `App\Models\Team` |
| `USER_TEAM_SYNC_QUEUE` | Queue name for jobs | `default` |
| `USER_TEAM_SYNC_QUEUE_CONNECTION` | Queue connection | `null` |
| `USER_TEAM_SYNC_TRIES` | Job retry attempts | `3` |
| `USER_TEAM_SYNC_BACKOFF` | Seconds between retries | `60` |
| `USER_TEAM_SYNC_TIMEOUT` | HTTP timeout in seconds | `10` |

## Testing

```bash
vendor/bin/pest
```

## License

MIT License. See [LICENSE](LICENSE) for details.
