<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Events\PasswordSynced;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncPasswordJob;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends password hash to sync-password endpoint', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-password' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncPasswordJob('user@example.com', '$2y$12$hashedpassword');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'sync-password')
            && $request->data()['email'] === 'user@example.com'
            && $request->data()['password_hash'] === '$2y$12$hashedpassword';
    });

    Event::assertDispatched(PasswordSynced::class);
});

it('dispatches SyncFailed on HTTP error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-password' => Http::response('Error', 500),
    ]);

    $job = new SyncPasswordJob('user@example.com', '$2y$12$hashedpassword');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Event::assertDispatched(SyncFailed::class);
});

it('logs password sync to sync_logs', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-password' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncPasswordJob('pwd@example.com', '$2y$12$hashedpassword');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'pwd@example.com')->where('action', 'sync_password')->first();
    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->status)->toBe('success');
});

it('does not include password hash in log payload', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/sync-password' => Http::response(['message' => 'ok'], 200),
    ]);

    $job = new SyncPasswordJob('secure@example.com', '$2y$12$hashedpassword');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'secure@example.com')->first();
    expect($log->payload)->toBeEmpty();
});
