<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

// --- Create User ---

it('creates a user with hashed password', function (): void {
    Event::fake();

    $passwordHash = Hash::make('secret');

    $response = $this->postJson('/api/create-user', [
        'email' => 'john@example.com',
        'name' => 'John Doe',
        'password_hash' => $passwordHash,
    ], authHeaders());

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'user_id']);

    $user = User::where('email', 'john@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->password)->toBe($passwordHash)
        ->and($user->is_active)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull();

    Event::assertDispatched(UserCreatedFromSync::class);
});

it('creates a user and attaches teams', function (): void {
    Event::fake();

    $team1 = Team::create(['name' => 'Team A', 'slug' => 'team-a']);
    $team2 = Team::create(['name' => 'Team B', 'slug' => 'team-b']);
    $passwordHash = Hash::make('secret');

    $response = $this->postJson('/api/create-user', [
        'email' => 'jane@example.com',
        'name' => 'Jane Doe',
        'password_hash' => $passwordHash,
        'team_ids' => [$team1->id, $team2->id],
    ], authHeaders());

    $response->assertStatus(201);

    $user = User::where('email', 'jane@example.com')->first();
    expect($user->teams)->toHaveCount(2);
});

it('does not hash the password again on create', function (): void {
    Event::fake();

    $passwordHash = Hash::make('my-password');

    $this->postJson('/api/create-user', [
        'email' => 'test@example.com',
        'name' => 'Test User',
        'password_hash' => $passwordHash,
    ], authHeaders());

    $user = User::where('email', 'test@example.com')->first();
    // The stored password should be exactly the hash we sent, not re-hashed
    expect($user->password)->toBe($passwordHash);
});

it('validates required fields on create', function (): void {
    $this->postJson('/api/create-user', [], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'name', 'password_hash']);
});

it('validates password_hash min length on create', function (): void {
    $this->postJson('/api/create-user', [
        'email' => 'test@example.com',
        'name' => 'Test',
        'password_hash' => 'short',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password_hash']);
});

it('rejects duplicate email on create', function (): void {
    User::create([
        'name' => 'Existing',
        'email' => 'exists@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/create-user', [
        'email' => 'exists@example.com',
        'name' => 'New',
        'password_hash' => Hash::make('pass'),
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('logs user creation to sync_logs', function (): void {
    Event::fake();

    $this->postJson('/api/create-user', [
        'email' => 'log@example.com',
        'name' => 'Log User',
        'password_hash' => Hash::make('pass'),
    ], authHeaders());

    expect(SyncLog::where('email', 'log@example.com')->where('action', 'create_user')->exists())->toBeTrue();
});

// --- Sync User ---

it('syncs user email', function (): void {
    Event::fake();

    $user = User::create([
        'name' => 'User',
        'email' => 'old@example.com',
        'password' => Hash::make('pass'),
    ]);

    $response = $this->postJson('/api/sync-user', [
        'email' => 'old@example.com',
        'new_email' => 'new@example.com',
    ], authHeaders());

    $response->assertOk();
    expect($user->fresh()->email)->toBe('new@example.com');

    Event::assertDispatched(UserSynced::class);
});

it('syncs user password hash without re-hashing', function (): void {
    Event::fake();

    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('old-password'),
    ]);

    $newHash = Hash::make('new-password');

    $this->postJson('/api/sync-user', [
        'email' => 'user@example.com',
        'password_hash' => $newHash,
    ], authHeaders());

    expect($user->fresh()->password)->toBe($newHash);
});

it('validates password_hash min length on sync', function (): void {
    User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/sync-user', [
        'email' => 'user@example.com',
        'password_hash' => 'short',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password_hash']);
});

it('returns 422 when syncing non-existent user', function (): void {
    $this->postJson('/api/sync-user', [
        'email' => 'nobody@example.com',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('logs user sync to sync_logs', function (): void {
    Event::fake();

    User::create([
        'name' => 'User',
        'email' => 'sync-log@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/sync-user', [
        'email' => 'sync-log@example.com',
        'new_email' => 'sync-log-new@example.com',
    ], authHeaders());

    expect(SyncLog::where('email', 'sync-log@example.com')->where('action', 'sync_user')->exists())->toBeTrue();
});

// --- Toggle Active ---

it('toggles user active status', function (): void {
    Event::fake();

    User::create([
        'name' => 'User',
        'email' => 'toggle@example.com',
        'password' => Hash::make('pass'),
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/toggle-user-active', [
        'email' => 'toggle@example.com',
        'is_active' => true,
    ], authHeaders());

    $response->assertOk();
    expect(User::where('email', 'toggle@example.com')->first()->is_active)->toBeTrue();

    Event::assertDispatched(UserActiveToggled::class, function ($event): bool {
        return $event->email === 'toggle@example.com' && $event->isActive === true;
    });
});

it('validates toggle active request', function (): void {
    $this->postJson('/api/toggle-user-active', [], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'is_active']);
});

it('logs toggle active to sync_logs', function (): void {
    Event::fake();

    User::create([
        'name' => 'User',
        'email' => 'toggle-log@example.com',
        'password' => Hash::make('pass'),
    ]);

    $this->postJson('/api/toggle-user-active', [
        'email' => 'toggle-log@example.com',
        'is_active' => true,
    ], authHeaders());

    expect(SyncLog::where('email', 'toggle-log@example.com')->where('action', 'toggle_active')->exists())->toBeTrue();
});
