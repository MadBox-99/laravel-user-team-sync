<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Events\TeamUpdatedFromSync;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\TeamWithoutUuidFillable;

it('renames a team matched by uuid even when the slug already drifted', function (): void {
    // This is the whole point of the feature: the receiver's slug no longer
    // matches the publisher's, so slug matching cannot find the team. The uuid
    // can.
    Event::fake();

    $uuid = (string) Str::uuid();
    $team = Team::create(['uuid' => $uuid, 'name' => 'Juskufa', 'slug' => 'juskufa-kft']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'juskufa',
        'name' => 'Juskufa Bt.',
        'slug' => 'juskufa-bt',
    ], authHeaders())->assertOk();

    expect($team->fresh())
        ->name->toBe('Juskufa Bt.')
        ->slug->toBe('juskufa-bt');

    Event::assertDispatched(TeamUpdatedFromSync::class);
});

it('falls back to the original slug when the team has no uuid', function (): void {
    $team = Team::create(['name' => 'Legacy', 'slug' => 'legacy']);

    $this->postJson('/api/update-team', [
        'uuid' => (string) Str::uuid(),
        'original_slug' => 'legacy',
        'slug' => 'legacy-kft',
    ], authHeaders())->assertOk();

    expect($team->fresh()->slug)->toBe('legacy-kft');
});

it('adopts the publisher uuid when it matched by slug fallback', function (): void {
    // Healing the mapping here is the same rule identity:push-uuids applies:
    // fill an empty uuid, never overwrite a differing one.
    $uuid = (string) Str::uuid();
    $team = Team::create(['name' => 'Legacy', 'slug' => 'legacy']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'legacy',
        'slug' => 'legacy-kft',
    ], authHeaders())->assertOk();

    expect($team->fresh()->uuid)->toBe($uuid);
});

it('refuses to rename a team whose uuid disagrees with the publisher', function (): void {
    // Same slug, different identity. Renaming it would silently retarget the
    // mapping onto the wrong team, which is worse than leaving it stale.
    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);

    $this->postJson('/api/update-team', [
        'uuid' => (string) Str::uuid(),
        'original_slug' => 'acme',
        'slug' => 'acme-kft',
    ], authHeaders())->assertStatus(409);

    expect($team->fresh()->slug)->toBe('acme');
});

it('returns 404 when the team is unknown to this receiver', function (): void {
    $this->postJson('/api/update-team', [
        'uuid' => (string) Str::uuid(),
        'original_slug' => 'never-seen',
        'slug' => 'never-seen-kft',
    ], authHeaders())->assertStatus(404);
});

it('refuses a rename that would collide with another team', function (): void {
    $uuid = (string) Str::uuid();
    $team = Team::create(['uuid' => $uuid, 'name' => 'Acme', 'slug' => 'acme']);
    Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Taken', 'slug' => 'taken']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'acme',
        'slug' => 'taken',
    ], authHeaders())->assertStatus(409);

    expect($team->fresh()->slug)->toBe('acme');
});

it('is idempotent when the receiver already holds the target slug', function (): void {
    // A queue retry must not turn into a 409 against the team's own slug.
    $uuid = (string) Str::uuid();
    $team = Team::create(['uuid' => $uuid, 'name' => 'Acme Kft.', 'slug' => 'acme-kft']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'acme',
        'name' => 'Acme Kft.',
        'slug' => 'acme-kft',
    ], authHeaders())->assertOk();

    expect($team->fresh()->slug)->toBe('acme-kft');
});

it('adopts the uuid on a Team model that does not list uuid as fillable', function (): void {
    // Receiver Team models are app-owned; every production receiver we upgraded
    // omits 'uuid' from $fillable. Mass assignment would report success and
    // silently discard it, leaving the mapping unhealed.
    config()->set('user-team-sync.models.team', TeamWithoutUuidFillable::class);

    $uuid = (string) Str::uuid();
    $team = TeamWithoutUuidFillable::create(['name' => 'Acme', 'slug' => 'acme']);

    expect($team->fresh()->uuid)->toBeNull();

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'acme',
        'name' => 'Acme Kft.',
        'slug' => 'acme-kft',
    ], authHeaders())->assertOk();

    expect($team->fresh())
        ->uuid->toBe($uuid)
        ->name->toBe('Acme Kft.')
        ->slug->toBe('acme-kft');
});

it('logs the inbound update', function (): void {
    $uuid = (string) Str::uuid();
    Team::create(['uuid' => $uuid, 'name' => 'Acme', 'slug' => 'acme']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'acme',
        'slug' => 'acme-kft',
    ], authHeaders())->assertOk();

    expect(SyncLog::query()->where('action', 'update_team')->where('direction', 'inbound')->exists())->toBeTrue();
});

it('rejects an unauthenticated request', function (): void {
    $uuid = (string) Str::uuid();
    $team = Team::create(['uuid' => $uuid, 'name' => 'Acme', 'slug' => 'acme']);

    $this->postJson('/api/update-team', [
        'uuid' => $uuid,
        'original_slug' => 'acme',
        'slug' => 'acme-kft',
    ])->assertStatus(401);

    expect($team->fresh()->slug)->toBe('acme');
});
