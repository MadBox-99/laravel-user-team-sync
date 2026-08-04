<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync;

use Illuminate\Support\ServiceProvider;
use Madbox99\UserTeamSync\Console\InstallCommand;
use Madbox99\UserTeamSync\Console\UpdateCommand;
use Madbox99\UserTeamSync\Publisher\Observers\TeamSyncObserver;
use Madbox99\UserTeamSync\Publisher\Observers\UserSyncObserver;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class UserTeamSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/user-team-sync.php', 'user-team-sync');

        $this->app->singleton(PublisherService::class);

        $this->app->instance('user-team-sync.receiving', false);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                UpdateCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/user-team-sync.php' => config_path('user-team-sync.php'),
        ], 'user-team-sync-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'user-team-sync-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $mode = config('user-team-sync.mode');

        if (in_array($mode, ['publisher', 'both'], true)) {
            $this->bootPublisher();
        }

        // In client mode the legacy receiver endpoints stay mounted for as long
        // as the transition switch is on: allowlisted users come in through SSO
        // while everyone else is still served by the publisher's push.
        $bootReceiver = in_array($mode, ['receiver', 'both'], true)
            || ($mode === 'client' && (bool) config('user-team-sync.client.legacy_receiver', true));

        if ($bootReceiver) {
            $this->bootReceiver();
        }

        if ($mode === 'client') {
            $this->bootClient();
        }
    }

    private function bootPublisher(): void
    {
        if (! config('user-team-sync.publisher.auto_observe')) {
            return;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model>|null $userModel */
        $userModel = config('user-team-sync.models.user');

        if ($userModel && class_exists($userModel)) {
            $userModel::observe(UserSyncObserver::class);
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model>|null $teamModel */
        $teamModel = config('user-team-sync.models.team');

        if ($teamModel && class_exists($teamModel)) {
            $teamModel::observe(TeamSyncObserver::class);
        }
    }

    private function bootReceiver(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/sync-api.php');
    }

    private function bootClient(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/identity-client.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'user-team-sync');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/user-team-sync'),
        ], 'user-team-sync-views');
    }
}
