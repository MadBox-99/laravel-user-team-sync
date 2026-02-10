<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\UserTeamSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            UserTeamSyncServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('user-team-sync.mode', 'receiver');
        $app['config']->set('user-team-sync.receiver.api_key', 'test-api-key');
        $app['config']->set('user-team-sync.models.user', \Madbox99\UserTeamSync\Tests\Fixtures\User::class);
        $app['config']->set('user-team-sync.models.team', \Madbox99\UserTeamSync\Tests\Fixtures\Team::class);
        $app['config']->set('user-team-sync.logging.enabled', true);
    }

    private function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        });

        // Run the package migration
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
