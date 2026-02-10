<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class CreateUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly string $ownerEmail,
    ) {
        $this->tries = (int) config('user-team-sync.publisher.tries', 3);
        $this->backoff = (int) config('user-team-sync.publisher.backoff', 60);
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
                }

                $response = $http->post("{$app['url']}/api/create-user", [
                    'email' => $this->email,
                    'name' => $this->name,
                    'password_hash' => $this->passwordHash,
                    'role' => $this->role,
                    'team_ids' => $teamIds,
                ]);

                $this->log($appName, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    event(new UserSynced($this->email, $appName, ['action' => 'create']));
                } else {
                    event(new SyncFailed($this->email, $appName, SyncAction::CreateUser->value, $response->body()));
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during user creation for {$this->email} to {$appName}: {$e->getMessage()}");

                $this->log($appName, false, null, $e->getMessage());

                throw $e;
            }
        }
    }

    private function log(string $appName, bool $success, ?int $httpStatus, ?string $error): void
    {
        if (! config('user-team-sync.logging.enabled')) {
            return;
        }

        SyncLog::query()->create([
            'action' => SyncAction::CreateUser->value,
            'direction' => 'outbound',
            'target_app' => $appName,
            'email' => $this->email,
            'payload' => ['name' => $this->name, 'role' => $this->role, 'owner_email' => $this->ownerEmail],
            'status' => $success ? 'success' : 'failed',
            'http_status' => $httpStatus,
            'error_message' => $error,
            'attempt' => $this->attempts(),
        ]);
    }
}
