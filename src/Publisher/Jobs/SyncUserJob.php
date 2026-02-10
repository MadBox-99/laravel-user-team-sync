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

final class SyncUserJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function __construct(
        public readonly string $email,
        public readonly array $changedData,
    ) {
        $this->initRetryConfig();
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

                $this->logOutbound(SyncAction::SyncUser, $this->email, $appName, $this->changedData, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    UserSynced::dispatch($this->email, $appName, $this->changedData);
                } else {
                    SyncFailed::dispatch($this->email, $appName, SyncAction::SyncUser->value, $response->body());
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during user sync for {$this->email} to {$appName}: {$e->getMessage()}");

                $this->logOutbound(SyncAction::SyncUser, $this->email, $appName, [], false, null, $e->getMessage());

                throw $e;
            }
        }
    }
}
