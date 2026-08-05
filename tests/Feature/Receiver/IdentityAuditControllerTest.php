<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

it('rejects requests without a valid api key', function (): void {
    $this->getJson('/api/identity-audit')->assertStatus(401);
});

it('returns teams, users, memberships and pending attachments', function (): void {
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $orphan = Team::create(['name' => 'Local Only', 'slug' => 'local-only']);

    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('pass'),
    ]);
    $user->teams()->attach($team);

    PendingTeamAttachment::create([
        'user_email' => 'ghost@example.com',
        'team_slug' => 'missing-team',
    ]);

    $response = $this->getJson('/api/identity-audit', authHeaders())->assertOk();

    expect($response->json('teams'))->toHaveCount(2)
        ->and(collect($response->json('teams'))->pluck('slug')->all())
        ->toBe(['acme', 'local-only'])
        ->and($response->json('users'))->toBe([
            ['id' => $user->id, 'uuid' => null, 'email' => 'user@example.com'],
        ])
        ->and($response->json('memberships'))->toBe([
            [
                'user_email' => 'user@example.com',
                'user_uuid' => null,
                'team_slug' => 'acme',
                'team_uuid' => null,
            ],
        ])
        ->and($response->json('pending_team_attachments'))->toBe([
            ['user_email' => 'ghost@example.com', 'team_slug' => 'missing-team'],
        ]);

    // The orphan team has no members — this is exactly what the audit must surface.
    expect(collect($response->json('memberships'))->pluck('team_slug'))
        ->not->toContain('local-only');
});

it('includes a uuid for every team, user and membership entry', function (): void {
    $teamUuid = (string) Illuminate\Support\Str::uuid();
    $userUuid = (string) Illuminate\Support\Str::uuid();

    $team = Team::create(['name' => 'Acme', 'slug' => 'acme', 'uuid' => $teamUuid]);
    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('pass'),
        'uuid' => $userUuid,
    ]);
    $user->teams()->attach($team);

    $response = $this->getJson('/api/identity-audit', authHeaders())->assertOk();

    expect($response->json('teams.0.uuid'))->toBe($teamUuid)
        ->and($response->json('users.0.uuid'))->toBe($userUuid)
        ->and($response->json('memberships.0'))->toBe([
            'user_email' => 'user@example.com',
            'user_uuid' => $userUuid,
            'team_slug' => 'acme',
            'team_uuid' => $teamUuid,
        ]);
});

it('keeps a missing uuid as null instead of breaking', function (): void {
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $user = User::create([
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => Hash::make('pass'),
    ]);
    $user->teams()->attach($team);

    $response = $this->getJson('/api/identity-audit', authHeaders())->assertOk();

    expect($response->json('teams.0.uuid'))->toBeNull()
        ->and($response->json('users.0.uuid'))->toBeNull()
        ->and($response->json('memberships.0.user_uuid'))->toBeNull()
        ->and($response->json('memberships.0.team_uuid'))->toBeNull();
});

it('omits record_counts when count_teams is not supplied', function (): void {
    Team::create(['name' => 'Acme', 'slug' => 'acme']);

    $response = $this->getJson('/api/identity-audit', authHeaders())->assertOk();

    expect($response->json())->not->toHaveKey('record_counts');
});

it('counts only the requested teams across team_id-bearing tables, excluding team_user', function (): void {
    // A second, arbitrary table the schema happens to scope by team — the
    // discovery must find this without it being named anywhere in the code.
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('team_id');
        $table->string('name');
    });

    $acme = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::create(['name' => 'Globex', 'slug' => 'globex']);

    $acmeUser1 = User::create(['name' => 'A1', 'email' => 'a1@example.com', 'password' => Hash::make('pass')]);
    $acmeUser2 = User::create(['name' => 'A2', 'email' => 'a2@example.com', 'password' => Hash::make('pass')]);
    $globexUser = User::create(['name' => 'G1', 'email' => 'g1@example.com', 'password' => Hash::make('pass')]);

    $acmeUser1->teams()->attach($acme);
    $acmeUser2->teams()->attach($acme);
    $globexUser->teams()->attach($globex);

    DB::table('projects')->insert([
        ['team_id' => $acme->id, 'name' => 'Acme Project 1'],
        ['team_id' => $acme->id, 'name' => 'Acme Project 2'],
        ['team_id' => $globex->id, 'name' => 'Globex Project'],
    ]);

    $response = $this->getJson('/api/identity-audit?count_teams=acme,globex', authHeaders())->assertOk();

    // acme: 2 team_user rows would exist too, but only "projects" counts.
    expect($response->json('record_counts'))->toBe([
        'acme' => 2,
        'globex' => 1,
    ]);

    Schema::dropIfExists('projects');
});

it('handles an unknown slug in count_teams without error', function (): void {
    Team::create(['name' => 'Acme', 'slug' => 'acme']);

    $response = $this->getJson('/api/identity-audit?count_teams=acme,does-not-exist', authHeaders())->assertOk();

    expect($response->json('record_counts'))->toBe(['acme' => 0]);
});

it('binds slugs instead of interpolating them, even when they look like SQL', function (): void {
    Team::create(['name' => 'Acme', 'slug' => 'acme']);

    $maliciousSlug = "acme'; DROP TABLE teams; --";

    $response = $this->getJson(
        '/api/identity-audit?'.http_build_query(['count_teams' => "acme,{$maliciousSlug}"]),
        authHeaders(),
    )->assertOk();

    // Nothing broke, no data for the bogus slug leaked in, and the teams
    // table is still intact — proof the value never reached raw SQL.
    expect($response->json('record_counts'))->toBe(['acme' => 0])
        ->and(Team::query()->count())->toBe(1);
});
