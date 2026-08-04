<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\UserTeamSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed> */
    protected array $configOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    /**
     * Reboot the application with extra configuration applied. Routes are
     * registered while the service provider boots, so anything that decides
     * which routes exist has to be in place before that — and a plain
     * config()->set() would be undone by defineEnvironment() on the way
     * through refreshApplication().
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function bootWithConfig(array $overrides): void
    {
        $this->configOverrides = $overrides;

        $this->refreshApplication();

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

        $app['config']->set('user-team-sync.client.app_key', 'crm');
        $app['config']->set('user-team-sync.client.identity_url', 'https://identity.test');
        $app['config']->set('user-team-sync.client.client_id', 'test-client-id');
        $app['config']->set('user-team-sync.client.client_secret', 'test-client-secret');
        $app['config']->set('user-team-sync.client.redirect_uri', 'https://app.test/auth/callback');
        $app['config']->set('user-team-sync.client.subscribe_url', 'https://identity.test');
        $app['config']->set('user-team-sync.client.allowlist', []);
        $app['config']->set('user-team-sync.client.legacy_receiver', true);
        $app['config']->set('user-team-sync.client.role_map', []);
        $app['config']->set('user-team-sync.client.revalidate_after_minutes', 15);
        $app['config']->set('user-team-sync.client.grace_hours', 24);
        $app['config']->set('auth.providers.users.model', \Madbox99\UserTeamSync\Tests\Fixtures\User::class);

        // Applied last so a test's own overrides always win.
        foreach ($this->configOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function setUpDatabase(): void
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

        // teams, team_user, sync_logs, sync_apps are created by package migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
