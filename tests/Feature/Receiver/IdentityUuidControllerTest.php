<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

it('rejects requests without a valid api key', function (): void {
    $this->postJson('/api/identity-uuids', [])->assertStatus(401);
});

it('writes uuids onto existing users and teams', function (): void {
    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('pass'),
    ]);
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);

    $userUuid = (string) Str::uuid();
    $teamUuid = (string) Str::uuid();

    $this->postJson('/api/identity-uuids', [
        'users' => [['email' => 'user@example.com', 'uuid' => $userUuid]],
        'teams' => [['slug' => 'acme', 'uuid' => $teamUuid]],
    ], authHeaders())
        ->assertOk()
        ->assertJson(['users_updated' => 1, 'teams_updated' => 1]);

    expect($user->refresh()->uuid)->toBe($userUuid)
        ->and($team->refresh()->uuid)->toBe($teamUuid);
});

it('reports records it could not find instead of failing', function (): void {
    $response = $this->postJson('/api/identity-uuids', [
        'users' => [['email' => 'ghost@example.com', 'uuid' => (string) Str::uuid()]],
        'teams' => [['slug' => 'no-such-team', 'uuid' => (string) Str::uuid()]],
    ], authHeaders())->assertOk();

    expect($response->json('users_updated'))->toBe(0)
        ->and($response->json('users_missing'))->toBe(['ghost@example.com'])
        ->and($response->json('teams_missing'))->toBe(['no-such-team']);
});

it('is idempotent', function (): void {
    Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $uuid = (string) Str::uuid();

    $payload = ['users' => [], 'teams' => [['slug' => 'acme', 'uuid' => $uuid]]];

    $this->postJson('/api/identity-uuids', $payload, authHeaders())->assertOk();
    $this->postJson('/api/identity-uuids', $payload, authHeaders())->assertOk();

    expect(Team::where('slug', 'acme')->first()->uuid)->toBe($uuid);
});

it('does not overwrite a uuid that is already set to a different value', function (): void {
    $existing = (string) Str::uuid();
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme', 'uuid' => $existing]);

    // Precondition: the record genuinely already carries a uuid before we
    // POST a different one, otherwise this test would also pass through the
    // "missing uuid" branch and never reach the conflict check.
    expect($team->refresh()->uuid)->toBe($existing);

    $response = $this->postJson('/api/identity-uuids', [
        'users' => [],
        'teams' => [['slug' => 'acme', 'uuid' => (string) Str::uuid()]],
    ], authHeaders())->assertOk();

    expect(Team::where('slug', 'acme')->first()->uuid)->toBe($existing)
        ->and($response->json('teams_conflicting'))->toBe(['acme']);
});
