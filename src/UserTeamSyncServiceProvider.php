<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync;

use Illuminate\Support\ServiceProvider;
use Madbox99\UserTeamSync\Console\InstallCommand;
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
            $this->commands([InstallCommand::class]);
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

        if (in_array($mode, ['receiver', 'both'], true)) {
            $this->bootReceiver();
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
    }

    private function bootReceiver(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/sync-api.php');
    }
}
