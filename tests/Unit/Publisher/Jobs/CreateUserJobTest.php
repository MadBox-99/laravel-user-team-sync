<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateUserJob;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.api_key', 'test-key');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
    ]);
});

it('sends password_hash field in HTTP request', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/user-teams*' => Http::response(['teams' => []], 200),
        'crm.test/api/create-user' => Http::response(['message' => 'ok', 'user_id' => 1], 201),
    ]);

    $passwordHash = Hash::make('secret');
    $job = new CreateUserJob('test@example.com', 'Test User', $passwordHash, 'admin', 'owner@example.com');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request) use ($passwordHash): bool {
        if (! str_contains($request->url(), 'create-user')) {
            return false;
        }

        return $request->data()['password_hash'] === $passwordHash
            && $request->data()['email'] === 'test@example.com'
            && ! isset($request->data()['password']);
    });

    Event::assertDispatched(UserSynced::class);
});

it('fetches owner teams before creating user', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/user-teams*' => Http::response([
            'teams' => [['id' => 1], ['id' => 3]],
        ], 200),
        'crm.test/api/create-user' => Http::response(['message' => 'ok', 'user_id' => 1], 201),
    ]);

    $job = new CreateUserJob('test@example.com', 'Test', Hash::make('pass'), 'admin', 'owner@example.com');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'create-user')) {
            return false;
        }

        return $request->data()['team_ids'] === [1, 3];
    });
});

it('dispatches SyncFailed event on HTTP failure', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/user-teams*' => Http::response(['teams' => []], 200),
        'crm.test/api/create-user' => Http::response('Server Error', 500),
    ]);

    $job = new CreateUserJob('test@example.com', 'Test', Hash::make('pass'), 'admin', 'owner@example.com');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Event::assertDispatched(SyncFailed::class);
    Event::assertNotDispatched(UserSynced::class);
});

it('logs outbound sync to sync_logs', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/user-teams*' => Http::response(['teams' => []], 200),
        'crm.test/api/create-user' => Http::response(['message' => 'ok', 'user_id' => 1], 201),
    ]);

    $job = new CreateUserJob('log@example.com', 'Log User', Hash::make('pass'), 'admin', 'owner@example.com');
    $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class));

    $log = SyncLog::where('email', 'log@example.com')->where('action', 'create_user')->first();
    expect($log)->not->toBeNull()
        ->and($log->direction)->toBe('outbound')
        ->and($log->target_app)->toBe('crm')
        ->and($log->status)->toBe('success');
});

it('throws exception on HTTP connection error', function (): void {
    Event::fake();
    Http::fake([
        'crm.test/api/user-teams*' => Http::response(['teams' => []], 200),
        'crm.test/api/create-user' => fn () => throw new \Exception('Connection refused'),
    ]);

    $job = new CreateUserJob('test@example.com', 'Test', Hash::make('pass'), 'admin', 'owner@example.com');

    expect(fn () => $job->handle(app(\Madbox99\UserTeamSync\Publisher\PublisherService::class)))
        ->toThrow(\Exception::class, 'Connection refused');
});

it('sends the uuid in the create-user payload', function (): void {
    Illuminate\Support\Facades\Http::fake([
        // Wildcard: the job appends a `user_email` query string to this
        // request, so a pattern without `*` never matches it — Laravel's
        // HTTP fake then lets the request fall through to the real network
        // instead of failing loudly, which is exactly what happened here.
        'https://crm.test/api/user-teams*' => Illuminate\Support\Facades\Http::response(['teams' => []]),
        'https://crm.test/api/create-user' => Illuminate\Support\Facades\Http::response(['user_id' => 1], 201),
    ]);

    config()->set('user-team-sync.publisher.app_source', 'config');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'k', 'active' => true],
    ]);

    $uuid = (string) Illuminate\Support\Str::uuid();

    (new Madbox99\UserTeamSync\Publisher\Jobs\CreateUserJob(
        email: 'new@example.com',
        name: 'New',
        passwordHash: Illuminate\Support\Facades\Hash::make('secret'),
        role: 'subscriber',
        ownerEmail: 'owner@example.com',
        uuid: $uuid,
    ))->handle(app(Madbox99\UserTeamSync\Publisher\PublisherService::class));

    Illuminate\Support\Facades\Http::assertSent(
        fn ($request): bool => $request->url() === 'https://crm.test/api/create-user'
            && $request['uuid'] === $uuid
    );

    Illuminate\Support\Facades\Http::assertSent(
        fn ($request): bool => str_starts_with($request->url(), 'https://crm.test/api/user-teams')
            && $request['user_email'] === 'owner@example.com'
    );
});
