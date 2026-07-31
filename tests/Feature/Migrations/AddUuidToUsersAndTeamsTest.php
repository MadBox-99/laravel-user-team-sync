<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;

it('adds a nullable uuid column to users and teams', function (): void {
    expect(Schema::hasColumn('users', 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeTrue();
});

it('allows a team without a uuid', function (): void {
    $team = Team::create(['name' => 'No Uuid', 'slug' => 'no-uuid']);

    expect($team->uuid)->toBeNull();
});

it('accepts a uuid as a fillable attribute', function (): void {
    $uuid = (string) Str::uuid();

    $team = Team::create(['name' => 'With Uuid', 'slug' => 'with-uuid', 'uuid' => $uuid]);

    expect($team->refresh()->uuid)->toBe($uuid);
});

it('rejects a duplicate team uuid', function (): void {
    $uuid = (string) Str::uuid();

    Team::create(['name' => 'First', 'slug' => 'first', 'uuid' => $uuid]);

    expect(fn () => Team::create(['name' => 'Second', 'slug' => 'second', 'uuid' => $uuid]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
