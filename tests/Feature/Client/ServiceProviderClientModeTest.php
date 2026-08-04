<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

function routeUris(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->all();
}

it('registers the auth routes in client mode', function (): void {
    $this->bootWithConfig(['user-team-sync.mode' => 'client']);

    expect(routeUris())
        ->toContain('auth/redirect')
        ->toContain('auth/callback');
});

it('does not register the auth routes in receiver mode', function (): void {
    // The other 10 apps stay on 'receiver'. If client routes leaked into that
    // mode, every one of them would publish an unconfigured OAuth entry point.
    $this->bootWithConfig(['user-team-sync.mode' => 'receiver']);

    expect(routeUris())
        ->not->toContain('auth/redirect')
        ->not->toContain('auth/callback');
});

it('keeps the legacy receiver endpoints available in client mode when the switch is on', function (): void {
    // Phase 3 runs both worlds side by side: allowlisted users go through SSO,
    // everyone else is still served by the legacy push.
    $this->bootWithConfig([
        'user-team-sync.mode' => 'client',
        'user-team-sync.client.legacy_receiver' => true,
    ]);

    expect(routeUris())->toContain('api/create-user');
});

it('drops the legacy receiver endpoints in client mode once the switch is off', function (): void {
    $this->bootWithConfig([
        'user-team-sync.mode' => 'client',
        'user-team-sync.client.legacy_receiver' => false,
    ]);

    expect(routeUris())->not->toContain('api/create-user');
});
