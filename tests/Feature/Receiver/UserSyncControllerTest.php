<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Models\PendingUserActivation;
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

it('attaches teams by slug when team_ids are not provided', function (): void {
    Event::fake();

    $team = Team::create(['name' => 'Slug Team', 'slug' => 'slug-team']);

    $this->postJson('/api/create-user', [
        'email' => 'slug@example.com',
        'name' => 'Slug User',
        'password_hash' => Hash::make('pass'),
        'team_slugs' => ['slug-team'],
    ], authHeaders())->assertStatus(201);

    $user = User::where('email', 'slug@example.com')->first();
    expect($user->teams->pluck('id')->all())->toContain($team->id);
});

it('stores pending attachments for unknown slugs', function (): void {
    Event::fake();

    $this->postJson('/api/create-user', [
        'email' => 'early-user@example.com',
        'name' => 'Early User',
        'password_hash' => Hash::make('pass'),
        'team_slugs' => ['not-yet-synced'],
    ], authHeaders())->assertStatus(201);

    expect(PendingTeamAttachment::where('user_email', 'early-user@example.com')
        ->where('team_slug', 'not-yet-synced')
        ->exists())->toBeTrue();

    $user = User::where('email', 'early-user@example.com')->first();
    expect($user->teams)->toHaveCount(0);
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

it('stores a pending activation when toggling a user that does not exist yet', function (): void {
    $response = $this->postJson('/api/toggle-user-active', [
        'email' => 'not-yet@example.com',
        'is_active' => true,
    ], authHeaders());

    $response->assertOk();

    expect(User::where('email', 'not-yet@example.com')->exists())->toBeFalse();
    expect(PendingUserActivation::where('user_email', 'not-yet@example.com')->first())
        ->not->toBeNull()
        ->is_active->toBeTrue();
});

it('keeps only the latest pending activation state per email', function (): void {
    $this->postJson('/api/toggle-user-active', ['email' => 'late@example.com', 'is_active' => true], authHeaders());
    $this->postJson('/api/toggle-user-active', ['email' => 'late@example.com', 'is_active' => false], authHeaders());

    expect(PendingUserActivation::where('user_email', 'late@example.com')->count())->toBe(1)
        ->and(PendingUserActivation::where('user_email', 'late@example.com')->first()->is_active)->toBeFalse();
});

it('applies and clears the pending activation when the user is later created', function (): void {
    // Activation toggle arrives before the user is synced to this app.
    $this->postJson('/api/toggle-user-active', [
        'email' => 'ordered@example.com',
        'is_active' => true,
    ], authHeaders());

    // Then the create-user event arrives.
    $this->postJson('/api/create-user', [
        'email' => 'ordered@example.com',
        'name' => 'Ordered User',
        'password_hash' => Hash::make('secret'),
    ], authHeaders())->assertStatus(201);

    $user = User::where('email', 'ordered@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and(PendingUserActivation::where('user_email', 'ordered@example.com')->exists())->toBeFalse();
});

it('stores the uuid supplied with a new user', function (): void {
    $uuid = (string) Illuminate\Support\Str::uuid();

    $this->postJson('/api/create-user', [
        'email' => 'uuid-user@example.com',
        'name' => 'Uuid User',
        'password_hash' => Hash::make('secret'),
        'uuid' => $uuid,
    ], authHeaders())->assertStatus(201);

    expect(User::where('email', 'uuid-user@example.com')->first()->uuid)->toBe($uuid);
});

it('still creates a user when no uuid is supplied', function (): void {
    $this->postJson('/api/create-user', [
        'email' => 'legacy-user@example.com',
        'name' => 'Legacy User',
        'password_hash' => Hash::make('secret'),
    ], authHeaders())->assertStatus(201);

    expect(User::where('email', 'legacy-user@example.com')->first()->uuid)->toBeNull();
});

it('persists the uuid even when the host app\'s User model has not added it to $fillable', function (): void {
    config(['user-team-sync.models.user' => Madbox99\UserTeamSync\Tests\Fixtures\UserWithoutUuidFillable::class]);

    // Precondition: this stand-in genuinely cannot mass-assign uuid, which is
    // the real-world condition on a receiver app that has not yet added
    // 'uuid' to its own User model's $fillable. Without this guard the test
    // could silently degrade into the already-covered default-fixture case.
    $model = new Madbox99\UserTeamSync\Tests\Fixtures\UserWithoutUuidFillable;
    expect($model->isFillable('uuid'))->toBeFalse();

    $uuid = (string) Illuminate\Support\Str::uuid();

    $this->postJson('/api/create-user', [
        'email' => 'unfillable-uuid-user@example.com',
        'name' => 'Unfillable Uuid User',
        'password_hash' => Hash::make('secret'),
        'uuid' => $uuid,
    ], authHeaders())->assertStatus(201);

    expect(Madbox99\UserTeamSync\Tests\Fixtures\UserWithoutUuidFillable::where('email', 'unfillable-uuid-user@example.com')->first()->uuid)->toBe($uuid);
});
