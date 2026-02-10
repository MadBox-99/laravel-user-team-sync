<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Models\SyncApp;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateTeamJob;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\ToggleUserActiveJob;
use Madbox99\UserTeamSync\Publisher\PublisherService;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.apps', [
        'crm' => ['url' => 'https://crm.test', 'api_key' => 'crm-key', 'active' => true],
        'shop' => ['url' => 'https://shop.test', 'api_key' => 'shop-key', 'active' => false],
    ]);
});

it('returns all apps', function (): void {
    $service = app(PublisherService::class);

    expect($service->getApps())->toHaveCount(2);
});

it('returns only active apps', function (): void {
    $service = app(PublisherService::class);

    $active = $service->getActiveApps();
    expect($active)->toHaveCount(1)
        ->and(array_keys($active))->toBe(['crm']);
});

it('returns app by name', function (): void {
    $service = app(PublisherService::class);

    expect($service->getApp('crm'))->not->toBeNull()
        ->and($service->getApp('crm')['url'])->toBe('https://crm.test')
        ->and($service->getApp('nonexistent'))->toBeNull();
});

it('returns api key for app', function (): void {
    $service = app(PublisherService::class);

    expect($service->getApiKey('crm'))->toBe('crm-key');
});

it('falls back to default api key', function (): void {
    config()->set('user-team-sync.publisher.api_key', 'default-key');
    config()->set('user-team-sync.publisher.apps.crm.api_key', null);

    $service = app(PublisherService::class);

    expect($service->getApiKey('crm'))->toBe('default-key');
});

it('dispatches CreateUserJob with hashed password', function (): void {
    Bus::fake();

    $service = app(PublisherService::class);
    $service->createUser('test@example.com', 'Test', 'plain-password', 'admin', 'owner@example.com');

    Bus::assertDispatched(CreateUserJob::class, function (CreateUserJob $job): bool {
        return $job->email === 'test@example.com'
            && $job->name === 'Test'
            && $job->role === 'admin'
            && $job->ownerEmail === 'owner@example.com'
            && Hash::check('plain-password', $job->passwordHash);
    });
});

it('dispatches SyncUserJob', function (): void {
    Bus::fake();

    $service = app(PublisherService::class);
    $service->syncUser('test@example.com', ['new_email' => 'new@example.com']);

    Bus::assertDispatched(SyncUserJob::class, function (SyncUserJob $job): bool {
        return $job->email === 'test@example.com'
            && $job->changedData === ['new_email' => 'new@example.com'];
    });
});

it('dispatches CreateTeamJob', function (): void {
    Bus::fake();

    $service = app(PublisherService::class);
    $service->createTeam('My Team', 'user@example.com', 'my-team', 'John');

    Bus::assertDispatched(CreateTeamJob::class, function (CreateTeamJob $job): bool {
        return $job->teamName === 'My Team'
            && $job->userEmail === 'user@example.com'
            && $job->slug === 'my-team';
    });
});

it('dispatches ToggleUserActiveJob', function (): void {
    Bus::fake();

    $service = app(PublisherService::class);
    $service->toggleUserActive('user@example.com', true, 'crm');

    Bus::assertDispatched(ToggleUserActiveJob::class, function (ToggleUserActiveJob $job): bool {
        return $job->userEmail === 'user@example.com'
            && $job->isActive === true
            && $job->appKey === 'crm';
    });
});

it('makes http client with bearer token', function (): void {
    $service = app(PublisherService::class);
    $app = ['url' => 'https://crm.test', 'api_key' => 'my-key', 'active' => true];

    $client = $service->makeHttpClient($app);

    expect($client)->toBeInstanceOf(\Illuminate\Http\Client\PendingRequest::class);
});

// Database source tests

it('returns all apps from database', function (): void {
    config()->set('user-team-sync.publisher.app_source', 'database');

    SyncApp::query()->create(['name' => 'crm', 'url' => 'https://crm.test', 'api_key' => 'crm-key', 'is_active' => true]);
    SyncApp::query()->create(['name' => 'shop', 'url' => 'https://shop.test', 'api_key' => 'shop-key', 'is_active' => false]);

    $service = app(PublisherService::class);
    $apps = $service->getApps();

    expect($apps)->toHaveCount(2)
        ->and($apps['crm']['url'])->toBe('https://crm.test')
        ->and($apps['shop']['url'])->toBe('https://shop.test');
});

it('returns only active apps from database', function (): void {
    config()->set('user-team-sync.publisher.app_source', 'database');

    SyncApp::query()->create(['name' => 'crm', 'url' => 'https://crm.test', 'is_active' => true]);
    SyncApp::query()->create(['name' => 'shop', 'url' => 'https://shop.test', 'is_active' => false]);

    $service = app(PublisherService::class);
    $active = $service->getActiveApps();

    expect($active)->toHaveCount(1)
        ->and(array_keys($active))->toBe(['crm']);
});

it('returns app by name from database', function (): void {
    config()->set('user-team-sync.publisher.app_source', 'database');

    SyncApp::query()->create(['name' => 'crm', 'url' => 'https://crm.test', 'api_key' => 'crm-key', 'is_active' => true]);

    $service = app(PublisherService::class);

    expect($service->getApp('crm'))->not->toBeNull()
        ->and($service->getApp('crm')['url'])->toBe('https://crm.test')
        ->and($service->getApp('nonexistent'))->toBeNull();
});

it('returns api key from database app', function (): void {
    config()->set('user-team-sync.publisher.app_source', 'database');

    SyncApp::query()->create(['name' => 'crm', 'url' => 'https://crm.test', 'api_key' => 'db-key', 'is_active' => true]);

    $service = app(PublisherService::class);

    expect($service->getApiKey('crm'))->toBe('db-key');
});

it('falls back to default api key for database app with null key', function (): void {
    config()->set('user-team-sync.publisher.app_source', 'database');
    config()->set('user-team-sync.publisher.api_key', 'default-key');

    SyncApp::query()->create(['name' => 'crm', 'url' => 'https://crm.test', 'api_key' => null, 'is_active' => true]);

    $service = app(PublisherService::class);

    expect($service->getApiKey('crm'))->toBe('default-key');
});
