<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Events\TeamCreatedFromSync;
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
