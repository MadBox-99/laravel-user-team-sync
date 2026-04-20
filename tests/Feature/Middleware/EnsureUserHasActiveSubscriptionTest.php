<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Madbox99\UserTeamSync\Receiver\Http\Middleware\EnsureUserHasActiveSubscription;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    Route::middleware(['web', EnsureUserHasActiveSubscription::class])
        ->get('/test-protected', fn () => response()->json(['ok' => true]));
});

it('allows active users through', function (): void {
    $user = User::create([
        'name' => 'Active User',
        'email' => 'active@example.com',
        'password' => Hash::make('pass'),
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/test-protected')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('blocks inactive users with 403 when no redirect url configured', function (): void {
    config()->set('user-team-sync.receiver.inactive_redirect_url', null);

    $user = User::create([
        'name' => 'Inactive User',
        'email' => 'inactive@example.com',
        'password' => Hash::make('pass'),
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/test-protected')
        ->assertStatus(403);
});

it('redirects inactive users when redirect url is configured', function (): void {
    config()->set('user-team-sync.receiver.inactive_redirect_url', 'https://subscribe.example.com/plans');

    $user = User::create([
        'name' => 'Inactive User',
        'email' => 'redirect@example.com',
        'password' => Hash::make('pass'),
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get('/test-protected')
        ->assertRedirect('https://subscribe.example.com/plans');
});

it('allows guests through (authentication is handled elsewhere)', function (): void {
    $this->getJson('/test-protected')
        ->assertOk();
});

it('bypasses inactive check on configured route patterns', function (): void {
    config()->set('user-team-sync.receiver.inactive_redirect_url', null);

    Route::middleware(['web', EnsureUserHasActiveSubscription::class])
        ->post('/app/logout', fn () => response()->json(['logged_out' => true]))
        ->name('filament.admin.auth.logout');

    $user = User::create([
        'name' => 'Inactive User',
        'email' => 'logout@example.com',
        'password' => Hash::make('pass'),
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/app/logout')
        ->assertOk()
        ->assertJson(['logged_out' => true]);
});

it('respects custom bypass patterns from config', function (): void {
    config()->set('user-team-sync.receiver.inactive_redirect_url', null);
    config()->set('user-team-sync.receiver.bypass_route_patterns', ['account.*']);

    Route::middleware(['web', EnsureUserHasActiveSubscription::class])
        ->get('/account/exit', fn () => response()->json(['ok' => true]))
        ->name('account.exit');

    $user = User::create([
        'name' => 'Inactive User',
        'email' => 'custom@example.com',
        'password' => Hash::make('pass'),
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->getJson('/account/exit')
        ->assertOk();
});
