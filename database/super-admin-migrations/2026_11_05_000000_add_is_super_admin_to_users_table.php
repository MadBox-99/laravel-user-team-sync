<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = (string) config('user-team-sync.super_admin.column', 'is_super_admin');

        if (Schema::hasColumn('users', $column)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($column): void {
            $table->boolean($column)->default(false)->index();
        });
    }

    public function down(): void
    {
        $column = (string) config('user-team-sync.super_admin.column', 'is_super_admin');

        if (! Schema::hasColumn('users', $column)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($column): void {
            $table->dropIndex([$column]);
            $table->dropColumn($column);
        });
    }
};
