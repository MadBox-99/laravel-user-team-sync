<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Madbox99\UserTeamSync\Events\TeamSynced;
use Madbox99\UserTeamSync\Events\TeamSyncFailed;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\RemoveTeamMemberJob;
use Madbox99\UserTeamSync\Publisher\PublisherService;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('posts the membership to end to the given app', function (): void {
    Event::fake();
    Http::fake(['crm.test/api/remove-team-member' => Http::response(['removed' => true], 200)]);

    (new RemoveTeamMemberJob('user@example.com', 'acme', 'crm', 'user-uuid', 'team-uuid'))
        ->handle(app(PublisherService::class));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://crm.test/api/remove-team-member'
        && $request->hasHeader('Authorization', 'Bearer crm-key')
        && $request->data() === [
            'user_email' => 'user@example.com',
            'user_uuid' => 'user-uuid',
            'team_slug' => 'acme',
            'team_uuid' => 'team-uuid',
        ]);

    Event::assertDispatched(TeamSynced::class, fn (TeamSynced $event): bool => $event->slug === 'acme' && $event->appName === 'crm');
});

it('skips when the app is not configured', function (): void {
    Http::fake();

    (new RemoveTeamMemberJob('user@example.com', 'acme', 'nonexistent'))->handle(app(PublisherService::class));

    Http::assertNothingSent();
});

it('logs a 404 as skipped rather than failed', function (): void {
    Event::fake();
    Http::fake(['crm.test/api/remove-team-member' => Http::response(['message' => 'Team not found'], 404)]);

    (new RemoveTeamMemberJob('user@example.com', 'acme', 'crm'))->handle(app(PublisherService::class));

    $log = SyncLog::query()->where('action', 'remove_team_member')->first();

    expect($log->status)->toBe('skipped')
        ->and($log->direction)->toBe('outbound')
        ->and($log->payload)->toBe(['team_slug' => 'acme']);

    Event::assertNotDispatched(TeamSyncFailed::class);
});

it('dispatches TeamSyncFailed on an HTTP error', function (): void {
    Event::fake();
    Http::fake(['crm.test/api/remove-team-member' => Http::response('Error', 500)]);

    (new RemoveTeamMemberJob('user@example.com', 'acme', 'crm'))->handle(app(PublisherService::class));

    Event::assertDispatched(TeamSyncFailed::class, fn (TeamSyncFailed $event): bool => $event->action === 'remove_team_member');
    expect(SyncLog::query()->where('action', 'remove_team_member')->value('status'))->toBe('failed');
});

it('rethrows a connection error so the queue retries', function (): void {
    Http::fake(['crm.test/api/remove-team-member' => fn () => throw new Exception('Connection refused')]);

    expect(fn () => (new RemoveTeamMemberJob('user@example.com', 'acme', 'crm'))->handle(app(PublisherService::class)))
        ->toThrow(Exception::class, 'Connection refused');
});

it('is dispatched by PublisherService::removeTeamMember', function (): void {
    Queue::fake();

    app(PublisherService::class)->removeTeamMember('user@example.com', 'acme', 'crm', 'user-uuid', 'team-uuid');

    Queue::assertPushed(RemoveTeamMemberJob::class, fn (RemoveTeamMemberJob $job): bool => $job->userEmail === 'user@example.com'
        && $job->teamSlug === 'acme'
        && $job->appKey === 'crm'
        && $job->userUuid === 'user-uuid'
        && $job->teamUuid === 'team-uuid');
});
