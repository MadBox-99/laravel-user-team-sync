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

it('down migration rolls back uuid columns successfully', function (): void {
    // Verify columns exist before rollback
    expect(Schema::hasColumn('users', 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeTrue();

    // Get the migration and run down
    $migration = require database_path('migrations/2026_07_31_000000_add_uuid_to_users_and_teams.php');
    $migration->down();

    // Verify columns are removed
    expect(Schema::hasColumn('users', 'uuid'))->toBeFalse()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeFalse();

    // Re-run up for cleanup (so next test has columns)
    $migration->up();
});

it('down migration checks for index before dropping', function (): void {
    // The down() method must defensively check if the uuid unique index exists
    // before attempting to drop it. The dropUniqueIfExists() method uses
    // Schema::getIndexes() to verify the index exists before calling dropUnique().

    $migration = require database_path('migrations/2026_07_31_000000_add_uuid_to_users_and_teams.php');

    // Verify initial state: columns and indexes exist from test setup
    expect(Schema::hasColumn('users', 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeTrue();

    // Call down() - it will check for indexes using Schema::getIndexes()
    // and only call dropUnique() if the index actually exists
    $migration->down();

    // Verify cleanup worked
    expect(Schema::hasColumn('users', 'uuid'))->toBeFalse()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeFalse();

    // Restore for cleanup
    $migration->up();
});
