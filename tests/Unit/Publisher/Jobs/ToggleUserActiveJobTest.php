<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\ToggleUserActiveJob;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends toggle active request to specific app', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/toggle-user-active' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new ToggleUserActiveJob('user@example.com', true, 'crm');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'toggle-user-active')
            && $request->data()['email'] === 'user@example.com'
            && $request->data()['is_active'] === true;
    });

    Event::assertDispatched(UserActiveToggled::class);
});

it('skips when app not found', function (): void {
    Event::fake();
    Http::fake();

    $job = new ToggleUserActiveJob('user@example.com', true, 'nonexistent');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertNothingSent();
    Event::assertNotDispatched(UserActiveToggled::class);
});

it('dispatches SyncFailed on HTTP error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/toggle-user-active' => Http::response('Error', 500),
    ]);

    $job = new ToggleUserActiveJob('user@example.com', true, 'crm');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Event::assertDispatched(SyncFailed::class);
});

it('logs toggle active to sync_logs', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/toggle-user-active' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new ToggleUserActiveJob('log@example.com', false, 'crm');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'log@example.com')->where('action', 'toggle_active')->first();
    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->payload)->toBe(['is_active' => false]);
});

it('throws exception on connection error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/toggle-user-active' => fn () => throw new \Exception('Connection refused'),
    ]);

    $job = new ToggleUserActiveJob('user@example.com', true, 'crm');

    expect(fn () => $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class)))
        ->toThrow(\Exception::class, 'Connection refused');
});
