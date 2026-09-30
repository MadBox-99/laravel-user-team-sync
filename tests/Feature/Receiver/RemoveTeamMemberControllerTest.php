<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Events\TeamMemberRemovedFromSync;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

function makeMember(array $attributes = []): User
{
    return User::create([
        'name' => 'Member',
        'email' => 'member@example.com',
        'password' => 'x',
        ...$attributes,
    ]);
}

it('detaches the user from the team and keeps the account', function (): void {
    Event::fake();

    $user = makeMember(['is_active' => true]);
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $other = Team::create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach([$team->id, $other->id]);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())
        ->assertOk()
        ->assertJson(['removed' => true]);

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->teams()->pluck('teams.id')->all())->toBe([$other->id]);

    Event::assertDispatched(TeamMemberRemovedFromSync::class, fn ($event): bool => $event->user->is($user) && $event->team->is($team));
});

it('is idempotent when the user is no longer a member', function (): void {
    Event::fake();

    makeMember();
    Team::create(['name' => 'Acme', 'slug' => 'acme']);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())
        ->assertOk()
        ->assertJson(['removed' => false]);

    Event::assertNotDispatched(TeamMemberRemovedFromSync::class);
});

it('matches user and team by uuid when email and slug drifted', function (): void {
    $userUuid = (string) Str::uuid();
    $teamUuid = (string) Str::uuid();

    $user = makeMember(['uuid' => $userUuid, 'email' => 'renamed@example.com']);
    $team = Team::create(['uuid' => $teamUuid, 'name' => 'Acme', 'slug' => 'acme-kft']);
    $user->teams()->attach($team->id);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'user_uuid' => $userUuid,
        'team_slug' => 'acme',
        'team_uuid' => $teamUuid,
    ], authHeaders())->assertOk()->assertJson(['removed' => true]);

    expect($user->teams()->count())->toBe(0);
});

it('refuses when the local team uuid disagrees with the publisher', function (): void {
    $user = makeMember();
    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);
    $user->teams()->attach($team->id);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
        'team_uuid' => (string) Str::uuid(),
    ], authHeaders())->assertStatus(409);

    expect($user->teams()->count())->toBe(1);
});

it('refuses when the local user uuid disagrees with the publisher', function (): void {
    $user = makeMember(['uuid' => (string) Str::uuid()]);
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $user->teams()->attach($team->id);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'user_uuid' => (string) Str::uuid(),
        'team_slug' => 'acme',
    ], authHeaders())->assertStatus(409);

    expect($user->teams()->count())->toBe(1);
});

it('returns 404 for an unknown user and still drops the pending attachment', function (): void {
    Team::create(['name' => 'Acme', 'slug' => 'acme']);
    PendingTeamAttachment::query()->create(['user_email' => 'ghost@example.com', 'team_slug' => 'acme']);
    PendingTeamAttachment::query()->create(['user_email' => 'ghost@example.com', 'team_slug' => 'keep']);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'ghost@example.com',
        'team_slug' => 'acme',
    ], authHeaders())->assertNotFound();

    expect(PendingTeamAttachment::query()->pluck('team_slug')->all())->toBe(['keep']);
});

it('returns 404 for an unknown team', function (): void {
    makeMember();

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'never-seen',
    ], authHeaders())->assertNotFound();
});

it('repoints a primary team_id that pointed at the removed team', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id')->nullable();
    });

    $user = makeMember();
    $removed = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $remaining = Team::create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach([$removed->id, $remaining->id]);
    DB::table('users')->where('id', $user->id)->update(['team_id' => $removed->id]);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('team_id'))->toBe($remaining->id);
});

it('nulls a primary team_id when no team remains', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id')->nullable();
    });

    $user = makeMember();
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $user->teams()->attach($team->id);
    DB::table('users')->where('id', $user->id)->update(['team_id' => $team->id]);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('team_id'))->toBeNull();
});

it('leaves a primary team_id pointing at another team alone', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id')->nullable();
    });

    $user = makeMember();
    $removed = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $primary = Team::create(['name' => 'Primary', 'slug' => 'primary']);
    $user->teams()->attach([$removed->id, $primary->id]);
    DB::table('users')->where('id', $user->id)->update(['team_id' => $primary->id]);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('team_id'))->toBe($primary->id);
});

it('logs the inbound removal', function (): void {
    $user = makeMember();
    $team = Team::create(['name' => 'Acme', 'slug' => 'acme']);
    $user->teams()->attach($team->id);

    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], authHeaders())->assertOk();

    expect(SyncLog::query()->where('action', 'remove_team_member')->where('direction', 'inbound')->where('email', 'member@example.com')->exists())->toBeTrue();
});

it('validates the payload', function (): void {
    $this->postJson('/api/remove-team-member', ['team_uuid' => 'nope'], authHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_email', 'team_slug', 'team_uuid']);
});

it('rejects requests without a valid api key', function (): void {
    $this->postJson('/api/remove-team-member', [
        'user_email' => 'member@example.com',
        'team_slug' => 'acme',
    ], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
});
