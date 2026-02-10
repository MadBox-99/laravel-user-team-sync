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

final class SyncUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $backoff;

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function __construct(
        public readonly string $email,
        public readonly array $changedData,
    ) {
        $this->tries = (int) config('user-team-sync.publisher.tries', 3);
        $this->backoff = (int) config('user-team-sync.publisher.backoff', 60);
    }

    public function handle(PublisherService $service): void
    {
        foreach ($service->getActiveApps() as $appName => $app) {
            try {
                $http = $service->makeHttpClient($app);

                $response = $http->post("{$app['url']}/api/sync-user", [
                    'email' => $this->email,
                    ...$this->changedData,
                ]);

                $this->log($appName, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    event(new UserSynced($this->email, $appName, $this->changedData));
                } else {
                    event(new SyncFailed($this->email, $appName, SyncAction::SyncUser->value, $response->body()));
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during user sync for {$this->email} to {$appName}: {$e->getMessage()}");

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
            'action' => SyncAction::SyncUser->value,
            'direction' => 'outbound',
            'target_app' => $appName,
            'email' => $this->email,
            'payload' => $this->changedData,
            'status' => $success ? 'success' : 'failed',
            'http_status' => $httpStatus,
            'error_message' => $error,
            'attempt' => $this->attempts(),
        ]);
    }
}
