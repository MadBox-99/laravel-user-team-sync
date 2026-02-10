<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Concerns;

use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Models\SyncLog;

trait LogsOutboundSync
{
    protected function initRetryConfig(): void
    {
        $this->tries = (int) config('user-team-sync.publisher.tries', 3);
        $this->backoff = (int) config('user-team-sync.publisher.backoff', 60);
    }

    protected function logOutbound(
        SyncAction $action,
        string $email,
        string $appName,
        array $payload,
        bool $success,
        ?int $httpStatus,
        ?string $error,
    ): void {
        if (! config('user-team-sync.logging.enabled')) {
            return;
        }

        SyncLog::query()->create([
            'action' => $action->value,
            'direction' => 'outbound',
            'target_app' => $appName,
            'email' => $email,
            'payload' => $payload,
            'status' => $success ? 'success' : 'failed',
            'http_status' => $httpStatus,
            'error_message' => $error,
            'attempt' => $this->attempts(),
        ]);
    }
}
