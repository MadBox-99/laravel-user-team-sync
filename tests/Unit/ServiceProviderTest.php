<?php

declare(strict_types=1);

use Madbox99\UserTeamSync\Publisher\PublisherService;

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
        ->and($routes)->toContain('api/user-teams')
        ->and($routes)->toContain('api/sync-password');
});

it('merges default config', function (): void {
    expect(config('user-team-sync.logging.retention_days'))->toBe(30)
        ->and(config('user-team-sync.publisher.tries'))->toBe(3)
        ->and(config('user-team-sync.publisher.backoff'))->toBe(60);
});
