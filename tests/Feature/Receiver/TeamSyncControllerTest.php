<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Events\TeamCreatedFromSync;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

it('creates a team', function (): void {
    Event::fake();

    $response = $this->postJson('/api/create-team', [
        'name' => 'New Team',
        'slug' => 'new-team',
    ], authHeaders());

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'team_id']);

    expect(Team::where('slug', 'new-team')->exists())->toBeTrue();

    Event::assertDispatched(TeamCreatedFromSync::class);
});

it('creates a team and attaches user', function (): void {
    Event::fake();

    $user = User::create([
        'name' => 'User',
        'email' => 'team-user@example.com',
        'password' => Hash::make('pass'),
    ]);

    $response = $this->postJson('/api/create-team', [
        'name' => 'User Team',
        'slug' => 'user-team',
        'user_email' => 'team-user@example.com',
    ], authHeaders());

    $response->assertStatus(201);

    $user->refresh();
    expect($user->teams)->toHaveCount(1)
        ->and($user->teams->first()->slug)->toBe('user-team');
});

it('stores a pending attachment when user_email is provided but user does not exist yet', function (): void {
    Event::fake();

    $response = $this->postJson('/api/create-team', [
        'name' => 'Orphan Team',
        'slug' => 'orphan-team',
        'user_email' => 'missing@example.com',
    ], authHeaders());

    $response->assertStatus(201);

    expect(PendingTeamAttachment::where('user_email', 'missing@example.com')
        ->where('team_slug', 'orphan-team')
        ->exists())->toBeTrue();
});

it('consumes matching pending attachments when the user is later created', function (): void {
    Event::fake();

    PendingTeamAttachment::create([
        'user_email' => 'late@example.com',
        'team_slug' => 'late-team',
    ]);

    Team::create(['name' => 'Late Team', 'slug' => 'late-team']);

    $this->postJson('/api/create-user', [
        'email' => 'late@example.com',
        'name' => 'Late User',
        'password_hash' => Hash::make('pass'),
    ], authHeaders())->assertStatus(201);

    $user = User::where('email', 'late@example.com')->first();
    expect($user->teams->pluck('slug')->all())->toContain('late-team')
        ->and(PendingTeamAttachment::where('user_email', 'late@example.com')->exists())->toBeFalse();
});

it('consumes matching pending attachments when the team arrives later', function (): void {
    Event::fake();

    User::create([
        'name' => 'Early',
        'email' => 'early@example.com',
        'password' => Hash::make('pass'),
    ]);

    PendingTeamAttachment::create([
        'user_email' => 'early@example.com',
        'team_slug' => 'delayed-team',
    ]);

    $this->postJson('/api/create-team', [
        'name' => 'Delayed Team',
        'slug' => 'delayed-team',
    ], authHeaders())->assertStatus(201);

    $user = User::where('email', 'early@example.com')->first();
    expect($user->teams->pluck('slug')->all())->toContain('delayed-team')
        ->and(PendingTeamAttachment::where('user_email', 'early@example.com')->exists())->toBeFalse();
});

it('rejects duplicate team slug', function (): void {
    Team::create(['name' => 'Existing', 'slug' => 'existing']);

    $this->postJson('/api/create-team', [
        'name' => 'Another',
        'slug' => 'existing',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

it('validates required team fields', function (): void {
    $this->postJson('/api/create-team', [], authHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'slug']);
});

it('logs team creation to sync_logs', function (): void {
    Event::fake();

    $this->postJson('/api/create-team', [
        'name' => 'Log Team',
        'slug' => 'log-team',
    ], authHeaders());

    expect(SyncLog::where('action', 'create_team')->exists())->toBeTrue();
});

it('returns user teams', function (): void {
    $user = User::create([
        'name' => 'User',
        'email' => 'teams@example.com',
        'password' => Hash::make('pass'),
    ]);

    $team1 = Team::create(['name' => 'Team 1', 'slug' => 'team-1']);
    $team2 = Team::create(['name' => 'Team 2', 'slug' => 'team-2']);
    $user->teams()->attach([$team1->id, $team2->id]);

    $response = $this->getJson('/api/user-teams?user_email=teams@example.com', authHeaders());

    $response->assertOk()
        ->assertJsonCount(2, 'teams');
});

it('returns empty teams for user without teams', function (): void {
    User::create([
        'name' => 'No Teams User',
        'email' => 'noteams@example.com',
        'password' => Hash::make('pass'),
    ]);

    $response = $this->getJson('/api/user-teams?user_email=noteams@example.com', authHeaders());

    $response->assertOk()
        ->assertJsonCount(0, 'teams');
});

it('validates user_email exists for get user teams', function (): void {
    $this->getJson('/api/user-teams?user_email=nobody@example.com', authHeaders())
        ->assertStatus(422);
});
