<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsOutboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\PasswordSynced;
use Madbox99\UserTeamSync\Events\SyncFailed;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class SyncPasswordJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $email,
        public readonly string $passwordHash,
    ) {
        $this->initRetryConfig();
    }

    public function handle(PublisherService $service): void
    {
        foreach ($service->getActiveApps() as $appName => $app) {
            try {
                $http = $service->makeHttpClient($app);

                $response = $http->post("{$app['url']}/api/sync-password", [
                    'email' => $this->email,
                    'password_hash' => $this->passwordHash,
                ]);

                $this->logOutbound(SyncAction::SyncPassword, $this->email, $appName, [], $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    PasswordSynced::dispatch($this->email);
                } else {
                    SyncFailed::dispatch($this->email, $appName, SyncAction::SyncPassword->value, $response->body());
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during password sync for {$this->email} to {$appName}: {$e->getMessage()}");

                $this->logOutbound(SyncAction::SyncPassword, $this->email, $appName, [], false, null, $e->getMessage());

                throw $e;
            }
        }
    }
}
