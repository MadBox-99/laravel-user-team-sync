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

it('adds a fallback login route in client mode that sends guests to the login url', function (): void {
    // Laravel's auth middleware redirects guests to route('login'). A Filament
    // app has no route by that name, so without this fallback a guest on any
    // 'auth' route got a "Route [login] not defined" 500 instead of a redirect.
    $this->bootWithConfig([
        'user-team-sync.mode' => 'client',
        'user-team-sync.client.login_url' => '/app/login',
    ]);

    Route::middleware(['web', 'auth'])->get('/protected', fn (): string => 'ok');

    expect(Route::has('login'))->toBeTrue();

    $this->get('/protected')->assertRedirect('/auth/login');
    $this->get('/auth/login')->assertRedirect('/app/login');
});

it('leaves an existing login route alone in client mode', function (): void {
    // Defined by the app before the package looks, so the package must not
    // shadow it with its own fallback.
    $this->routesBeforeBoot = function ($router): void {
        $router->get('/sign-in', fn (): string => 'app login')->name('login');
    };

    $this->bootWithConfig(['user-team-sync.mode' => 'client']);

    expect(routeUris())->not->toContain('auth/login')
        ->and(route('login', absolute: false))->toBe('/sign-in');
});

it('does not add the fallback login route in receiver mode', function (): void {
    $this->bootWithConfig(['user-team-sync.mode' => 'receiver']);

    expect(routeUris())->not->toContain('auth/login');
});
