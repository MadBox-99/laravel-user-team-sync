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
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class ToggleUserActiveJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $userEmail,
        public readonly bool $isActive,
        public readonly string $appKey,
    ) {
        $this->initRetryConfig();
    }

    public function handle(PublisherService $service): void
    {
        $app = $service->getApp($this->appKey);

        if (! $app) {
            Log::warning("UserTeamSync: App '{$this->appKey}' not found in config", [
                'user_email' => $this->userEmail,
                'available_apps' => array_keys($service->getApps()),
            ]);

            return;
        }

        try {
            $http = $service->makeHttpClient($app);

            $response = $http->post("{$app['url']}/api/toggle-user-active", [
                'email' => $this->userEmail,
                'is_active' => $this->isActive,
            ]);

            $this->logOutbound(SyncAction::ToggleActive, $this->userEmail, $this->appKey, ['is_active' => $this->isActive], $response->successful(), $response->status(), $response->successful() ? null : $response->body());

            if ($response->successful()) {
                UserActiveToggled::dispatch($this->userEmail, $this->isActive);
            } else {
                SyncFailed::dispatch($this->userEmail, $this->appKey, SyncAction::ToggleActive->value, $response->body());
            }
        } catch (Exception $e) {
            Log::error("UserTeamSync: Exception during toggle active for {$this->userEmail} to {$this->appKey}: {$e->getMessage()}");

            $this->logOutbound(SyncAction::ToggleActive, $this->userEmail, $this->appKey, [], false, null, $e->getMessage());

            throw $e;
        }
    }
}
