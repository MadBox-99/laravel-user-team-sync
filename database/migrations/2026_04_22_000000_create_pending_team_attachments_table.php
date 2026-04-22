<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_team_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('user_email')->index();
            $table->string('team_slug')->index();
            $table->timestamps();

            $table->unique(['user_email', 'team_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_team_attachments');
    }
};
