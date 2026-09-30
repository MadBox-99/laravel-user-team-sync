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
 * Tells one receiver app that a user's membership of a team has ended.
 *
 * Only the membership goes: the receiver never deletes or deactivates the user
 * account in response. Pair it with ToggleUserActiveJob when access to the app
 * itself is lost as well.
 *
 * Carries both keys for each side, like UpdateTeamJob: the uuids are the stable
 * identity, the email and slug are what a receiver that predates the uuid
 * backfill still recognises.
 */
final class RemoveTeamMemberJob implements ShouldQueue
{
    use LogsOutboundSync, Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(
        public readonly string $userEmail,
        public readonly string $teamSlug,
        public readonly string $appKey,
        public readonly ?string $userUuid = null,
        public readonly ?string $teamUuid = null,
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

        $payload = ['team_slug' => $this->teamSlug];

        try {
            $response = $service->makeHttpClient($app)->post("{$app['url']}/api/remove-team-member", [
                'user_email' => $this->userEmail,
                'user_uuid' => $this->userUuid,
                'team_slug' => $this->teamSlug,
                'team_uuid' => $this->teamUuid,
            ]);

            // The receiver does not know the user or the team: the membership
            // it is asked to end does not exist there, which is the desired
            // state. Not a failure, and nothing a retry would change.
            if ($response->status() === 404) {
                $this->logOutbound(SyncAction::RemoveTeamMember, $this->userEmail, $this->appKey, $payload, false, 404, null, 'skipped');

                return;
            }

            $this->logOutbound(SyncAction::RemoveTeamMember, $this->userEmail, $this->appKey, $payload, $response->successful(), $response->status(), $response->successful() ? null : $response->body());

            if ($response->successful()) {
                TeamSynced::dispatch($this->teamSlug, $this->appKey, ['removed_member' => $this->userEmail]);
            } else {
                TeamSyncFailed::dispatch($this->teamSlug, $this->appKey, SyncAction::RemoveTeamMember->value, $response->body());
            }
        } catch (Exception $e) {
            Log::error("UserTeamSync: Exception during team member removal for {$this->userEmail} from {$this->teamSlug} on {$this->appKey}: {$e->getMessage()}");

            $this->logOutbound(SyncAction::RemoveTeamMember, $this->userEmail, $this->appKey, $payload, false, null, $e->getMessage());

            throw $e;
        }
    }
}
