<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Events\TeamSynced;
use Madbox99\UserTeamSync\Events\TeamSyncFailed;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\UpdateTeamJob;
use Madbox99\UserTeamSync\Publisher\PublisherService;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.app_source', 'config');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends the uuid, the original slug and the changed fields', function (): void {
    Event::fake();
    Http::fake(['https://crm.test/api/update-team' => Http::response(['message' => 'ok'])]);

    $uuid = (string) Str::uuid();

    (new UpdateTeamJob($uuid, 'acme', ['name' => 'Acme Kft.', 'slug' => 'acme-kft']))
        ->handle(app(PublisherService::class));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://crm.test/api/update-team'
        && $request['uuid'] === $uuid
        && $request['original_slug'] === 'acme'
        && $request['name'] === 'Acme Kft.'
        && $request['slug'] === 'acme-kft');

    Event::assertDispatched(TeamSynced::class);
});

it('dispatches TeamSyncFailed on an HTTP error', function (): void {
    Event::fake();
    Http::fake(['https://crm.test/api/update-team' => Http::response('Error', 500)]);

    (new UpdateTeamJob(null, 'acme', ['slug' => 'acme-kft']))->handle(app(PublisherService::class));

    Event::assertDispatched(TeamSyncFailed::class);
});

it('does not treat a 404 as a failure', function (): void {
    // A receiver that never had the team is the normal case, not an outage:
    // the publisher fans out to every active app regardless of entitlement,
    // so most apps legitimately do not know most teams. Logging these as
    // failures would bury the real ones.
    Event::fake();
    Http::fake(['https://crm.test/api/update-team' => Http::response(['message' => 'Team not found'], 404)]);

    (new UpdateTeamJob(null, 'acme', ['slug' => 'acme-kft']))->handle(app(PublisherService::class));

    $log = SyncLog::query()->where('action', 'update_team')->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('skipped')
        ->and($log->http_status)->toBe(404);

    Event::assertNotDispatched(TeamSyncFailed::class);
});

it('logs a successful update to sync_logs with the original slug in the payload', function (): void {
    Event::fake();
    Http::fake(['https://crm.test/api/update-team' => Http::response(['message' => 'ok'])]);

    (new UpdateTeamJob(null, 'acme', ['slug' => 'acme-kft']))->handle(app(PublisherService::class));

    $log = SyncLog::query()->where('action', 'update_team')->first();

    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->status)->toBe('success')
        ->and($log->target_app)->toBe('crm')
        ->and($log->payload['original_slug'])->toBe('acme');
});

it('keeps going when one app reports a conflict', function (): void {
    // One receiver disagreeing about identity must not stop the rename from
    // reaching the others.
    Event::fake();

    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'k', 'active' => true],
        'mes' => ['url' => 'https://mes.test', 'api_key' => 'k', 'active' => true],
    ]);

    Http::fake([
        'https://crm.test/api/update-team' => Http::response(['message' => 'conflict'], 409),
        'https://mes.test/api/update-team' => Http::response(['message' => 'ok']),
    ]);

    (new UpdateTeamJob(null, 'acme', ['slug' => 'acme-kft']))->handle(app(PublisherService::class));

    expect(SyncLog::query()->where('target_app', 'mes')->where('status', 'success')->exists())->toBeTrue()
        ->and(SyncLog::query()->where('target_app', 'crm')->where('status', 'failed')->exists())->toBeTrue();
});
