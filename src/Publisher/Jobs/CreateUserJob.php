<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsOutboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class CreateUserJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly string $ownerEmail,
    ) {
        $this->initRetryConfig();
    }

    public function handle(PublisherService $service): void
    {
        foreach ($service->getActiveApps() as $appName => $app) {
            try {
                $http = $service->makeHttpClient($app);

                $teamsResponse = $http->get("{$app['url']}/api/user-teams", [
                    'user_email' => $this->ownerEmail,
                ]);

                $teamIds = [];
                if ($teamsResponse->successful()) {
                    $teams = $teamsResponse->json('teams', []);
                    $teamIds = array_column($teams, 'id');
                } else {
                    Log::warning("UserTeamSync: Failed to fetch teams for {$this->ownerEmail} from {$appName}", [
                        'status' => $teamsResponse->status(),
                    ]);
                }

                $response = $http->post("{$app['url']}/api/create-user", [
                    'email' => $this->email,
                    'name' => $this->name,
                    'password_hash' => $this->passwordHash,
                    'role' => $this->role,
                    'team_ids' => $teamIds,
                ]);

                $this->logOutbound(SyncAction::CreateUser, $this->email, $appName, ['name' => $this->name, 'role' => $this->role, 'owner_email' => $this->ownerEmail], $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    UserSynced::dispatch($this->email, $appName, ['action' => 'create']);
                } else {
                    SyncFailed::dispatch($this->email, $appName, SyncAction::CreateUser->value, $response->body());
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during user creation for {$this->email} to {$appName}: {$e->getMessage()}");

                $this->logOutbound(SyncAction::CreateUser, $this->email, $appName, [], false, null, $e->getMessage());

                throw $e;
            }
        }
    }
}
