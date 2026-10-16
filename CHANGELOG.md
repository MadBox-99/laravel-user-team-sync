# Changelog


All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.18.1] - 2026-10-16

### Fixed
- Publisher (`app_source = database`): a `sync_apps` row whose `api_key` cannot be decrypted
  (e.g. written unencrypted, bypassing the model's `encrypted` cast) no longer throws
  `DecryptException` out of `getApps()` / `getActiveApps()` / `getApp()`. That row is logged at
  `error` level and skipped; every other app keeps syncing. Before, one such row made every
  `CreateUserJob`, `SyncUserJob`, `CreateTeamJob`, … fail for all apps.

## [1.18.0] - 2026-10-16

### Added
- `receiver.local_only_roles` (default `[]`): roles an app grants by hand, e.g.
  `['Super-Admin']`. The SSO login / revalidation, `/api/create-user` and `/api/sync-user`
  never hand one out (an incoming local-only role becomes `receiver.default_role`) and never
  take one away (a held one is kept next to the synced Spatie role; a plain `users.role`
  column holding one is left untouched). Exact, case-sensitive names. Replaces the
  per-app `PreservesLocalSuperAdmin` `syncRoles()` override.
- `Support\RoleWriter`: the single place where a sync writes a user's role.

### Changed
- `Client\IdentityProvisioner` and `Receiver\Http\Controllers\UserSyncController` now take a
  `Support\RoleWriter` constructor argument. Resolve them from the container
  (`resolve(IdentityProvisioner::class)`); a hand-written `new IdentityProvisioner()` (e.g. in a
  test) throws `ArgumentCountError` from 1.18.0 on.

## [1.17.0] - 2026-09-30

### Added
- Team membership removal. `RemoveTeamMemberJob` (publisher, one job per app — also via
  `UserTeamSync::removeTeamMember()`) posts to the new receiver endpoint
  `POST /api/remove-team-member` with `user_email`, `team_slug` and the optional
  `user_uuid` / `team_uuid`. The receiver detaches the user from the local team and
  never deletes or deactivates the account.
  - Identity follows `update-team`: uuid first, then email / slug; a local uuid that
    disagrees with the incoming one is a `409`.
  - Idempotent: an already-ended membership is `200` with `removed: false`; an unknown
    user or team is `404`, logged by the publisher as `skipped` (no retry, no
    `TeamSyncFailed`). A matching `pending_team_attachments` row is always dropped, so
    a late `create-user` cannot resurrect the membership.
  - A host `users.team_id` (primary team) that pointed at the removed team is repointed
    to the lowest remaining team id, or `NULL`.
  - Inbound and outbound `sync_logs` entries under the new `SyncAction::RemoveTeamMember`
    (`remove_team_member`); receiver fires `TeamMemberRemovedFromSync`.
- Client (SSO) mode is unchanged: it still writes the token's complete team state at
  login and on revalidation.

## [1.16.0] - 2026-08-05

### Fixed
- `IdentityProvisioner::applyRole()` now honours `client.role_map` on the non-Spatie
  (plain `users.role` column) path as well, instead of writing the raw claim role. A
  receiver whose local vocabulary differs from the publisher's — e.g. a `users.role`
  cast to an enum of `admin|technician|viewer` against the publisher's
  `admin|manager|subscriber` — could not sign such a user in at all: the write threw
  `ValueError: "subscriber" is not a valid backing value for enum`. The map was written
  and ignored.

  Not a behaviour change for a receiver whose vocabulary already equals the publisher's:
  an unmapped claim role is still written verbatim. On the column path the claim role is
  treated as its own local vocabulary, so `receiver.default_role` remains unreachable
  there, exactly as before.

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
