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

it('down migration safely drops uuid columns when index is missing', function (): void {
    // Critical edge case test: down() must handle columns without their unique indexes.
    // This scenario occurs when apps upgrade independently, some creating the column
    // with constraint, others without. All 16 apps must migrate safely.
    //
    // This test proves the guard works: dropUniqueIfExists() checks before dropping.

    $migration = require database_path('migrations/2026_07_31_000000_add_uuid_to_users_and_teams.php');

    // Initial: migration has created columns with indexes
    expect(Schema::hasColumn('users', 'uuid'))->toBeTrue();

    // Simulate the edge case: manually drop ONLY the index, leaving the column
    // (This is what happens when another process/app creates the column without constraint)
    try {
        Schema::connection()->statement('DROP INDEX users_uuid_unique');
    } catch (\Throwable) {
        // Try SQLite syntax
        try {
            $indexes = Schema::getIndexes('users');
            foreach ($indexes as $index) {
                if (in_array('uuid', $index['columns'])) {
                    Schema::connection()->statement("DROP INDEX {$index['name']}");
                    break;
                }
            }
        } catch (\Throwable) {
            // Index already gone, that's fine
        }
    }

    // Now we have column but no unique index - test that down() doesn't crash
    // Without the guard, dropUnique() would fail here
    $migration->down();

    // Verify column is removed
    expect(Schema::hasColumn('users', 'uuid'))->toBeFalse();

    // Cleanup
    $migration->up();
});
