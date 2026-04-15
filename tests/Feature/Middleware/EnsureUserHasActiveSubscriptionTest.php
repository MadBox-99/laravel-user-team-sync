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
