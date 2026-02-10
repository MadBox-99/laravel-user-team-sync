<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Concerns\LogsOutboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class CreateTeamJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    public readonly string $slug;

    public function __construct(
        public readonly string $teamName,
        public readonly string $userEmail,
        ?string $slug = null,
        public readonly ?string $userName = null,
    ) {
        $this->slug = $slug ?? Str::slug($teamName);
        $this->initRetryConfig();
    }

    public function handle(PublisherService $service): void
    {
        foreach ($service->getActiveApps() as $appName => $app) {
            try {
                $http = $service->makeHttpClient($app);

                $response = $http->post("{$app['url']}/api/create-team", [
                    'name' => $this->teamName,
                    'slug' => $this->slug,
                    'user_email' => $this->userEmail,
                    'user_name' => $this->userName,
                ]);

                $this->logOutbound(SyncAction::CreateTeam, $this->userEmail, $appName, ['team_name' => $this->teamName, 'slug' => $this->slug], $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    UserSynced::dispatch($this->userEmail, $appName, ['action' => 'create_team', 'team' => $this->teamName]);
                } else {
                    SyncFailed::dispatch($this->userEmail, $appName, SyncAction::CreateTeam->value, $response->body());
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during team creation for {$this->teamName} to {$appName}: {$e->getMessage()}");

                $this->logOutbound(SyncAction::CreateTeam, $this->userEmail, $appName, [], false, null, $e->getMessage());

                throw $e;
            }
        }
    }
}
