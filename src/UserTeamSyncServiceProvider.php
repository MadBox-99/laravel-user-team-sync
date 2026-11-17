<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Madbox99\UserTeamSync\Client\GuestRedirectFallback;
use Madbox99\UserTeamSync\Concerns\HasSuperAdmin;
use Madbox99\UserTeamSync\Console\InstallCommand;
use Madbox99\UserTeamSync\Console\SuperAdminCommand;
use Madbox99\UserTeamSync\Console\UpdateCommand;
use Madbox99\UserTeamSync\Publisher\Observers\TeamSyncObserver;
use Madbox99\UserTeamSync\Publisher\Observers\UserSyncObserver;
use Madbox99\UserTeamSync\Publisher\PublisherService;
use Madbox99\UserTeamSync\Support\SuperAdmin;

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
                SuperAdminCommand::class,
            ]);
        }

        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // Opt-in: an app adopting the global super admin flag publishes this
        // migration; it is not auto-loaded, so the hub and apps that do not use
        // HasSuperAdmin never get the column.
        $this->publishesMigrations([
            __DIR__.'/../database/super-admin-migrations/' => database_path('migrations'),
        ], 'user-team-sync-super-admin');

        $this->registerSuperAdminGate();

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

    /**
     * Super admins pass every ability (`Gate::before`) when the user model uses
     * HasSuperAdmin and `super_admin.gate_bypass` is on.
     */
    private function registerSuperAdminGate(): void
    {
        if (! config('user-team-sync.super_admin.gate_bypass', true)) {
            return;
        }

        Gate::before(function (mixed $user): ?bool {
            if (is_object($user) && SuperAdmin::usesTrait($user) && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
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

    /**
     * Laravel's auth middleware sends guests to route('login'). A client app
     * whose login lives elsewhere (a Filament panel's /app/login) has no route
     * by that name, so a guest on any 'auth' route got a "Route [login] not
     * defined" 500. The guest redirect is wrapped instead: the app's own
     * redirect (normally route('login')) is tried first, and only a missing
     * route falls back to the configured login_url.
     *
     * No route is registered for this: a fallback route named 'login' clashed
     * with panels that register 'login' later and broke `route:cache`.
     *
     * The app's redirect is set when the HTTP kernel is resolved; in a web
     * request that already happened, in tests and queues it happens later,
     * so the wrap is applied now and again after the kernel resolves.
     */
    private function wrapGuestRedirect(): void
    {
        $wrap = static function (): void {
            $property = new \ReflectionProperty(Authenticate::class, 'redirectToCallback');
            $previous = $property->getValue();

            if ($previous instanceof GuestRedirectFallback) {
                return;
            }

            Authenticate::redirectUsing(new GuestRedirectFallback(is_callable($previous) ? $previous : null));
        };

        if ($this->app->resolved(HttpKernel::class)) {
            $wrap();
        }

        $this->app->afterResolving(HttpKernel::class, $wrap);
    }

    private function bootClient(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/identity-client.php');
        $this->wrapGuestRedirect();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'user-team-sync');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/user-team-sync'),
        ], 'user-team-sync-views');
    }
}
