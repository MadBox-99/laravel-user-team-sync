<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class CreateTeamJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
        $this->tries = (int) config('user-team-sync.publisher.tries', 3);
        $this->backoff = (int) config('user-team-sync.publisher.backoff', 60);
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

                $this->log($appName, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    event(new UserSynced($this->userEmail, $appName, ['action' => 'create_team', 'team' => $this->teamName]));
                } else {
                    event(new SyncFailed($this->userEmail, $appName, SyncAction::CreateTeam->value, $response->body()));
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during team creation for {$this->teamName} to {$appName}: {$e->getMessage()}");

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
            'action' => SyncAction::CreateTeam->value,
            'direction' => 'outbound',
            'target_app' => $appName,
            'email' => $this->userEmail,
            'payload' => ['team_name' => $this->teamName, 'slug' => $this->slug],
            'status' => $success ? 'success' : 'failed',
            'http_status' => $httpStatus,
            'error_message' => $error,
            'attempt' => $this->attempts(),
        ]);
    }
}
