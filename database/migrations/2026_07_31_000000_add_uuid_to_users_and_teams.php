<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global, stable identifiers for cross-app identity.
 *
 * Nullable on purpose: existing rows are backfilled by a separate command, and
 * apps that have not been backfilled yet must keep working unchanged. The
 * local `id` columns are untouched — business tables foreign-key to them and
 * Filament tenancy resolves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', function (Blueprint $blueprint): void {
                $blueprint->char('uuid', 36)->nullable()->unique()->after('id');
            });
        }

        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'uuid')) {
            Schema::table('teams', function (Blueprint $blueprint): void {
                $blueprint->char('uuid', 36)->nullable()->unique()->after('id');
            });
        }
    }

    public function down(): void
    {
        // Drop unique constraint if it exists (defensive: may have been created elsewhere)
        if (Schema::hasColumn('users', 'uuid')) {
            try {
                Schema::table('users', function (Blueprint $blueprint): void {
                    $blueprint->dropUnique(['uuid']);
                });
            } catch (\Exception) {
                // Index may not exist if created by a different migration or manually without constraint
            }
        }

        if (Schema::hasColumn('teams', 'uuid')) {
            try {
                Schema::table('teams', function (Blueprint $blueprint): void {
                    $blueprint->dropUnique(['uuid']);
                });
            } catch (\Exception) {
                // Index may not exist if created by a different migration or manually without constraint
            }
        }

        // Drop the columns
        if (Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', function (Blueprint $blueprint): void {
                $blueprint->dropColumn('uuid');
            });
        }

        if (Schema::hasColumn('teams', 'uuid')) {
            Schema::table('teams', function (Blueprint $blueprint): void {
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
