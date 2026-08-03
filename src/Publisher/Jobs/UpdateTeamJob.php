<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsOutboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\TeamSynced;
use Madbox99\UserTeamSync\Events\TeamSyncFailed;
use Madbox99\UserTeamSync\Publisher\PublisherService;

/**
 * Propagates a team rename to every active receiver.
 *
 * Carries both keys on purpose. The uuid is the stable identity and is what the
 * receiver should match on; the original slug is the only thing a receiver that
 * predates the uuid backfill still recognises. Sending the *pre-save* slug is
 * essential — the receiver knows the team under its old name, not the new one.
 */
final class UpdateTeamJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function __construct(
        public readonly ?string $uuid,
        public readonly string $originalSlug,
        public readonly array $changedData,
    ) {
        $this->initRetryConfig();
    }

    public function handle(PublisherService $service): void
    {
        foreach ($service->getActiveApps() as $appName => $app) {
            try {
                $response = $service->makeHttpClient($app)->post("{$app['url']}/api/update-team", [
                    'uuid' => $this->uuid,
                    'original_slug' => $this->originalSlug,
                    ...$this->changedData,
                ]);

                $payload = ['original_slug' => $this->originalSlug, ...$this->changedData];

                // Most apps legitimately do not know most teams: create-team
                // fans out to every active app regardless of entitlement. A 404
                // here is the expected outcome, not an outage, so it must not
                // look like a failure or raise for a retry.
                if ($response->status() === 404) {
                    $this->logOutbound(SyncAction::UpdateTeam, '', $appName, $payload, false, 404, null, 'skipped');

                    continue;
                }

                $this->logOutbound(SyncAction::UpdateTeam, '', $appName, $payload, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

                if ($response->successful()) {
                    TeamSynced::dispatch($this->originalSlug, $appName, $this->changedData);
                } else {
                    TeamSyncFailed::dispatch($this->originalSlug, $appName, SyncAction::UpdateTeam->value, $response->body());
                }
            } catch (Exception $e) {
                Log::error("UserTeamSync: Exception during team update for {$this->originalSlug} to {$appName}: {$e->getMessage()}");

                $this->logOutbound(SyncAction::UpdateTeam, '', $appName, [], false, null, $e->getMessage());

                throw $e;
            }
        }
    }
}
