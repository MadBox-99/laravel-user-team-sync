# Laravel User Team Sync

Laravel package for synchronizing users and teams across multiple Laravel applications via a Publisher/Receiver pattern.

## Architecture

- **Publisher**: Sends sync events to other apps via HTTP + queue jobs
- **Receiver**: Exposes API endpoints to accept sync events
- **Both**: Acts as publisher and receiver simultaneously

Config key: `user-team-sync.mode` (`publisher` | `receiver` | `both`)

## Project Structure

```
src/
├── Publisher/
│   ├── PublisherService.php       # Central orchestrator, dispatches jobs
│   ├── Observers/
│   │   └── UserSyncObserver.php   # Auto-syncs on User model changes
│   └── Jobs/
│       ├── CreateUserJob.php      # Broadcasts new user to all active apps
│       ├── SyncUserJob.php        # Broadcasts field changes
│       ├── CreateTeamJob.php      # Creates team on receiver apps
│       └── ToggleUserActiveJob.php # Toggles active status on specific app
├── Receiver/
│   └── Http/
│       ├── Controllers/           # API endpoints for incoming sync
│       ├── Middleware/
│       │   └── ValidateSyncApiKey.php  # Bearer token auth
│       └── Requests/              # Form request validation
├── Events/                        # Dispatchable events for hooks
├── Enums/
│   └── SyncAction.php             # create_user, sync_user, etc.
├── Models/
│   └── SyncLog.php                # Audit log model
├── Facades/
│   └── UserTeamSync.php           # Facade for PublisherService
└── UserTeamSyncServiceProvider.php
```

## Key Conventions

- **Password handling**: Passwords are always hashed on the publisher side. The receiver stores the hash directly (`password_hash` field). Never send plain text passwords.
- **Infinite loop prevention**: Receiver uses `saveQuietly()` and an `app('user-team-sync.receiving')` flag to prevent observer re-triggers.
- **Observer**: Only watches fields listed in `config('user-team-sync.publisher.sync_fields')` (default: `email`, `role`).
- **Email changes**: The observer maps email changes to `new_email` key, using the original email as identifier.

## Testing

- **Framework**: Pest PHP + Orchestra Testbench
- **Run**: `vendor/bin/pest`
- **Test models**: `tests/Fixtures/User.php` and `tests/Fixtures/Team.php` (SQLite in-memory)
- **TestCase**: Sets up `receiver` mode, test API key `test-api-key`, in-memory DB with users/teams/team_user/sync_logs tables

## Code Style

- PHP 8.3+, `declare(strict_types=1)` everywhere
- Laravel Pint for formatting (`vendor/bin/pint`)
- All classes are `final`
- Typed properties, constructor promotion, named arguments
- BackedEnum for action types
