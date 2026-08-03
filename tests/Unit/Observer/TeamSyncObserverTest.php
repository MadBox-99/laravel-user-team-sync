<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Publisher\Jobs\UpdateTeamJob;
use Madbox99\UserTeamSync\Publisher\Observers\TeamSyncObserver;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.auto_observe', true);
    config()->set('user-team-sync.publisher.team_sync_fields', ['name', 'slug']);

    // Attach manually: the ServiceProvider already booted in receiver mode.
    Team::observe(TeamSyncObserver::class);
});

it('dispatches UpdateTeamJob when the slug changes', function (): void {
    Bus::fake();

    $uuid = (string) Str::uuid();
    $team = Team::create(['uuid' => $uuid, 'name' => 'Acme', 'slug' => 'acme']);

    $team->slug = 'acme-kft';
    $team->save();

    Bus::assertDispatched(UpdateTeamJob::class, fn (UpdateTeamJob $job): bool => $job->uuid === $uuid
        && $job->originalSlug === 'acme'
        && $job->changedData === ['slug' => 'acme-kft']);
});

it('dispatches UpdateTeamJob when the name changes', function (): void {
    Bus::fake();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);

    $team->name = 'Acme Kft.';
    $team->save();

    Bus::assertDispatched(UpdateTeamJob::class, fn (UpdateTeamJob $job): bool => $job->changedData === ['name' => 'Acme Kft.']);
});

it('sends the pre-save slug as the fallback identifier', function (): void {
    // The receiver still knows the team under its OLD slug, so a rename must
    // carry that value — not the new one — or the fallback match cannot find it.
    Bus::fake();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'old-slug']);

    $team->name = 'Renamed';
    $team->slug = 'new-slug';
    $team->save();

    Bus::assertDispatched(UpdateTeamJob::class, fn (UpdateTeamJob $job): bool => $job->originalSlug === 'old-slug');
});

it('does not dispatch when an unwatched field changes', function (): void {
    config()->set('user-team-sync.publisher.team_sync_fields', ['slug']);

    Bus::fake();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);

    $team->name = 'Renamed';
    $team->save();

    Bus::assertNotDispatched(UpdateTeamJob::class);
});

it('does not dispatch on create', function (): void {
    // CreateTeamJob already owns the create path; a second event would make
    // every registration send two writes to every receiver.
    Bus::fake();

    Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);

    Bus::assertNotDispatched(UpdateTeamJob::class);
});

it('does not dispatch while applying an inbound sync', function (): void {
    Bus::fake();

    $team = Team::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme']);

    app()->instance('user-team-sync.receiving', true);

    $team->slug = 'acme-kft';
    $team->save();

    Bus::assertNotDispatched(UpdateTeamJob::class);
});

it('still dispatches for a team that has no uuid yet', function (): void {
    // Teams predating the UUID backfill must keep propagating renames via the
    // slug fallback rather than silently going stale.
    Bus::fake();

    $team = Team::create(['name' => 'Legacy', 'slug' => 'legacy']);

    $team->slug = 'legacy-kft';
    $team->save();

    Bus::assertDispatched(UpdateTeamJob::class, fn (UpdateTeamJob $job): bool => $job->uuid === null
        && $job->originalSlug === 'legacy');
});
