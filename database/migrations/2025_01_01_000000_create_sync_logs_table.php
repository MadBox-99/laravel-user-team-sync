<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('user-team-sync.logging.table', 'sync_logs');

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('action');
            $blueprint->string('direction');
            $blueprint->string('target_app')->nullable();
            $blueprint->string('email')->index();
            $blueprint->json('payload')->nullable();
            $blueprint->string('status');
            $blueprint->text('error_message')->nullable();
            $blueprint->integer('http_status')->nullable();
            $blueprint->integer('attempt')->default(1);
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('user-team-sync.logging.table', 'sync_logs'));
    }
};
