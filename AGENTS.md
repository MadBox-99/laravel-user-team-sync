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
├── Concerns/
│   ├── LogsOutboundSync.php       # Shared trait for publisher jobs (retry config + logging)
│   └── LogsInboundSync.php        # Shared trait for receiver controllers (logging)
├── Console/
│   └── InstallCommand.php         # php artisan user-team-sync:install
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
│       │   └── ValidateSyncApiKey.php  # Bearer token auth + receiving guard
│       └── Requests/              # Form request validation
├── Events/                        # Dispatchable events for hooks
├── Enums/
│   └── SyncAction.php             # create_user, sync_user, etc.
├── Models/
│   └── SyncLog.php                # Audit log model ($guarded = [])
├── Facades/
│   └── UserTeamSync.php           # Facade for PublisherService
└── UserTeamSyncServiceProvider.php
```

## Key Conventions

- **Password handling**: Passwords are always hashed on the publisher side. The receiver stores the hash directly (`password_hash` field). Never send plain text passwords.
- **Infinite loop prevention**: `ValidateSyncApiKey` middleware sets `app('user-team-sync.receiving', true)` during inbound requests (with try/finally reset). Observer checks this flag and skips dispatch when true. Controllers use `saveQuietly()` where needed.
- **Observer**: Only watches fields listed in `config('user-team-sync.publisher.sync_fields')` (default: `email`, `role`).
- **Email changes**: The observer maps email changes to `new_email` key, using the original email as identifier.

## API Endpoints (Receiver)

All require Bearer token via `ValidateSyncApiKey` middleware.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/create-user` | Create user with `password_hash` |
| POST | `/api/sync-user` | Update user fields |
| POST | `/api/toggle-user-active` | Set active/inactive |
| POST | `/api/create-team` | Create team, optionally attach user |
| GET | `/api/user-teams` | Get user's team IDs |
| POST | `/api/sync-password` | Sync pre-hashed password |

## Testing

- **Framework**: Pest PHP + Orchestra Testbench
- **Run**: `vendor/bin/pest`
- **Test models**: `tests/Fixtures/User.php` and `tests/Fixtures/Team.php` (SQLite in-memory)

## Shared Traits

- **`LogsOutboundSync`** (publisher jobs): Provides `initRetryConfig()` for retry/backoff from config, and `logOutbound()` for SyncLog entries.
- **`LogsInboundSync`** (receiver controllers): Provides `logInbound()` for SyncLog entries.

## Code Style

- PHP 8.3+, `declare(strict_types=1)` everywhere
- Laravel Pint for formatting (`vendor/bin/pint`)
- All classes are `final`
- Typed properties, constructor promotion, named arguments
- BackedEnum for action types
- Static event dispatch (`Event::dispatch()`) instead of `event()` helper
