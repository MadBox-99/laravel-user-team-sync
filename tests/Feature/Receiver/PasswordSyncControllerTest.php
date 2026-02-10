<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Events\PasswordSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

it('syncs password hash directly', function (): void {
    Event::fake();

    $user = User::create([
        'name' => 'User',
        'email' => 'pw@example.com',
        'password' => Hash::make('old'),
    ]);

    $newHash = Hash::make('new-password');

    $response = $this->postJson('/api/sync-password', [
        'email' => 'pw@example.com',
        'password_hash' => $newHash,
    ], authHeaders());

    $response->assertOk()
        ->assertJson(['message' => 'Password synced successfully']);

    expect($user->fresh()->password)->toBe($newHash);

    Event::assertDispatched(PasswordSynced::class, fn ($event) => $event->email === 'pw@example.com');
});

it('validates required fields for password sync', function (): void {
    $this->postJson('/api/sync-password', [], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password_hash']);
});

it('validates password_hash min length', function (): void {
    User::create([
        'name' => 'User',
        'email' => 'pw2@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/sync-password', [
        'email' => 'pw2@example.com',
        'password_hash' => 'too-short',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password_hash']);
});

it('validates email exists for password sync', function (): void {
    $this->postJson('/api/sync-password', [
        'email' => 'nonexistent@example.com',
        'password_hash' => Hash::make('pass'),
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('logs password sync to sync_logs', function (): void {
    Event::fake();

    User::create([
        'name' => 'User',
        'email' => 'pw-log@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/sync-password', [
        'email' => 'pw-log@example.com',
        'password_hash' => Hash::make('new'),
    ], authHeaders());

    expect(SyncLog::where('email', 'pw-log@example.com')->where('action', 'sync_password')->exists())->toBeTrue();
});
