<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
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
            ['id' => $user->id, 'email' => 'user@example.com'],
        ])
        ->and($response->json('memberships'))->toBe([
            ['user_email' => 'user@example.com', 'team_slug' => 'acme'],
        ])
        ->and($response->json('pending_team_attachments'))->toBe([
            ['user_email' => 'ghost@example.com', 'team_slug' => 'missing-team'],
        ]);

    // The orphan team has no members — this is exactly what the audit must surface.
    expect(collect($response->json('memberships'))->pluck('team_slug'))
        ->not->toContain('local-only');
});
