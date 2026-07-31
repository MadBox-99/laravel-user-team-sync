<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;

/**
 * Absolute path to the migration under test.
 *
 * Deliberately not `database_path()`: under Testbench that resolves to the
 * skeleton app inside `vendor/`, which can hold a stale published copy of this
 * migration. Requiring it from the package directory guarantees the rollback
 * tests exercise the code this package actually ships.
 */
function uuidMigrationPath(): string
{
    return __DIR__.'/../../../database/migrations/2026_07_31_000000_add_uuid_to_users_and_teams.php';
}

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
    $migration = require uuidMigrationPath();
    $migration->down();

    // Verify columns are removed
    expect(Schema::hasColumn('users', 'uuid'))->toBeFalse()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeFalse();

    // Re-run up for cleanup (so next test has columns)
    $migration->up();
});

it('down migration rolls back when the uuid unique index is missing', function (): void {
    $migration = require uuidMigrationPath();

    // Construct the exact state the guard in down() exists for: an app whose
    // `uuid` column is present but whose unique index is not. Dropping the
    // index unconditionally here crashes on MySQL 8 with error 1091.
    Schema::table('users', function (Blueprint $blueprint): void {
        $blueprint->dropUnique(['uuid']);
    });

    Schema::table('teams', function (Blueprint $blueprint): void {
        $blueprint->dropUnique(['uuid']);
    });

    // Prove the setup above really happened — column kept, index gone. Without
    // this the test could pass against a silently failed strip.
    expect(array_column(Schema::getIndexes('users'), 'name'))->not->toContain('users_uuid_unique')
        ->and(Schema::hasColumn('users', 'uuid'))->toBeTrue()
        ->and(array_column(Schema::getIndexes('teams'), 'name'))->not->toContain('teams_uuid_unique')
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeTrue();

    // Any exception thrown here fails the test — no catch, by design.
    $migration->down();

    expect(Schema::hasColumn('users', 'uuid'))->toBeFalse()
        ->and(Schema::hasColumn('teams', 'uuid'))->toBeFalse();

    // Restore the schema for any test that shares this connection.
    $migration->up();
});
