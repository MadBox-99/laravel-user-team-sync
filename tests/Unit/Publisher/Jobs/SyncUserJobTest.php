<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncUserJob;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends changed data to sync-user endpoint', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-user' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncUserJob('user@example.com', ['new_email' => 'new@example.com', 'role' => 'editor']);
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'sync-user')
            && $request->data()['email'] === 'user@example.com'
            && $request->data()['new_email'] === 'new@example.com'
            && $request->data()['role'] === 'editor';
    });

    Event::assertDispatched(UserSynced::class);
});

it('dispatches SyncFailed on HTTP error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-user' => Http::response('Error', 500),
    ]);

    $job = new SyncUserJob('user@example.com', ['role' => 'editor']);
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Event::assertDispatched(SyncFailed::class);
});

it('logs sync to sync_logs', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-user' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncUserJob('sync-log@example.com', ['role' => 'editor']);
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'sync-log@example.com')->where('action', 'sync_user')->first();
    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->status)->toBe('success');
});

it('broadcasts to all active apps', function (): void {
    Event::fake();
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'key', 'active' => true],
        'shop' => ['url' => 'https://shop.test', 'api_key' => 'key', 'active' => true],
        'inactive' => ['url' => 'https://off.test', 'api_key' => 'key', 'active' => false],
    ]);

    Http::fake([
        'crm.test/*' => Http::response(['message' => 'ok'], 200),
        'shop.test/*' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncUserJob('user@example.com', ['role' => 'admin']);
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSentCount(2);
    Event::assertDispatched(UserSynced::class, 2);
});
