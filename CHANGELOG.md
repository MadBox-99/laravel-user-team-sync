# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-02-10

### Added
- `SyncApp` Eloquent model for database-based app storage (encrypted `api_key`, dynamic table name)
- `sync_apps` migration with configurable table name (`publisher.apps_table`)
- `app_source` config option (`config` | `database`) for choosing app storage strategy
- Database source support in `PublisherService` (`getApps`, `getActiveApps`, `getApp`)
- Interactive app management in `user-team-sync:install` command (app source selection + DB app creation)
- Tests for `SyncApp` model and `PublisherService` database source

## [1.0.1] - 2026-02-10

### Added
- `LogsOutboundSync` shared trait for publisher jobs (retry config + outbound logging)
- `LogsInboundSync` shared trait for receiver controllers (inbound logging)
- Receiving guard in `ValidateSyncApiKey` middleware (prevents infinite observer loops in `both` mode)
- `php artisan user-team-sync:install` interactive install command
- Middleware test for receiving guard reset

### Changed
- Publisher jobs refactored to use `LogsOutboundSync` trait (reduced ~80 lines of duplication)
- Receiver controllers refactored to use `LogsInboundSync` trait
- Static event dispatch (`Event::dispatch()`) instead of `event()` helper across all jobs and controllers
- `SyncLog` model uses `$guarded = []` instead of `$fillable` array
- Removed unnecessary `(string)` cast in `PublisherService::makeHttpClient()`
- Removed redundant `(int)` casts from `env()` calls in config
- Qualified `teams.id` column in `TeamSyncController::getUserTeams()` to fix ambiguous column error
- Consistent JSON response format across all receiver controllers
- Consolidated test `authHeaders()` helper into shared `Pest.php`
- Removed unused `User` import from middleware test

## [1.0.0] - 2026-02-10

### Added
- Initial release of the Laravel User Team Sync package
- Publisher/Receiver architecture for cross-app user and team synchronization
- Automatic user sync via Eloquent observer (`auto_observe` config)
- Manual sync via `UserTeamSync` facade
- Queue jobs: `CreateUserJob`, `SyncUserJob`, `CreateTeamJob`, `ToggleUserActiveJob`
- Receiver API endpoints with Bearer token authentication
- `SyncLog` model for audit logging
- Events: `UserCreatedFromSync`, `UserSynced`, `PasswordSynced`, `UserActiveToggled`, `TeamCreatedFromSync`, `SyncFailed`
- Spatie Permission support with fallback to `role` column
- Pre-hashed password sync (passwords hashed on publisher, stored directly on receiver)
- Configurable sync fields, retry attempts, backoff, HTTP timeout
- SSL skip for `.test` domains
- 77 tests (Pest PHP + Orchestra Testbench)
- GitHub Actions CI (PHP 8.3/8.4) and auto-release workflows
