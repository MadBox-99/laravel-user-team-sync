<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Publisher\Jobs\UpdateTeamJob;
use Madbox99\UserTeamSync\Publisher\PublisherService;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\UserTeamSyncServiceProvider;

it('registers PublisherService as singleton', function (): void {
    $instance1 = app(PublisherService::class);
    $instance2 = app(PublisherService::class);

    expect($instance1)->toBe($instance2);
});

it('registers receiving flag as false', function (): void {
    expect(app('user-team-sync.receiving'))->toBeFalse();
});

it('loads receiver routes when mode is receiver', function (): void {
    config()->set('user-team-sync.mode', 'receiver');

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->toArray();

    expect($routes)->toContain('api/create-user')
        ->and($routes)->toContain('api/sync-user')
        ->and($routes)->toContain('api/toggle-user-active')
        ->and($routes)->toContain('api/create-team')
        ->and($routes)->toContain('api/update-team')
        ->and($routes)->toContain('api/user-teams')
        ->and($routes)->toContain('api/sync-password');
});

it('attaches the team observer when booting in publisher mode', function (): void {
    // The observer is what makes rename propagation run at all. Every other
    // test in this feature attaches it by hand, so without this one the whole
    // thing could ship unwired and still look green.
    Bus::fake();

    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.auto_observe', true);
    config()->set('user-team-sync.models.team', Team::class);

    (new UserTeamSyncServiceProvider(app()))->boot();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);
    $team->slug = 'acme-kft';
    $team->save();

    Bus::assertDispatched(UpdateTeamJob::class);
});

it('does not attach the team observer when auto_observe is off', function (): void {
    Bus::fake();

    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.auto_observe', false);
    config()->set('user-team-sync.models.team', Team::class);

    (new UserTeamSyncServiceProvider(app()))->boot();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);
    $team->slug = 'acme-kft';
    $team->save();

    Bus::assertNotDispatched(UpdateTeamJob::class);
});

it('merges default config', function (): void {
    expect(config('user-team-sync.logging.retention_days'))->toBe(30)
        ->and(config('user-team-sync.publisher.tries'))->toBe(3)
        ->and(config('user-team-sync.publisher.backoff'))->toBe(60);
});
