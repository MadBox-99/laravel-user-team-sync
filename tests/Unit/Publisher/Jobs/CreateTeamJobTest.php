<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateTeamJob;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends team creation request', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/create-team' => Http::response(['message' => 'ok', 'team_id' => 1], 201),
    ]);

    $job = new CreateTeamJob('My Team', 'user@example.com', 'my-team', 'John');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'create-team')
            && $request->data()['name'] === 'My Team'
            && $request->data()['slug'] === 'my-team'
            && $request->data()['user_email'] === 'user@example.com';
    });

    Event::assertDispatched(UserSynced::class);
});

it('auto-generates slug from team name', function (): void {
    $job = new CreateTeamJob('My Awesome Team', 'user@example.com');

    expect($job->slug)->toBe('my-awesome-team');
});

it('uses provided slug', function (): void {
    $job = new CreateTeamJob('My Team', 'user@example.com', 'custom-slug');

    expect($job->slug)->toBe('custom-slug');
});

it('dispatches SyncFailed on HTTP error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/create-team' => Http::response('Error', 500),
    ]);

    $job = new CreateTeamJob('Team', 'user@example.com', 'team');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Event::assertDispatched(SyncFailed::class);
});

it('logs team creation to sync_logs', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/create-team' => Http::response(['message' => 'ok', 'team_id' => 1], 201),
    ]);

    $job = new CreateTeamJob('Team', 'log@example.com', 'team');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'log@example.com')->where('action', 'create_team')->first();
    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->status)->toBe('success');
});
