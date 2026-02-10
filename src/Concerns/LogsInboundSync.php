<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Concerns;

use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Models\SyncLog;

trait LogsInboundSync
{
    protected function logInbound(SyncAction $action, string $email): void
    {
        if (! config('user-team-sync.logging.enabled')) {
            return;
        }

        SyncLog::query()->create([
            'action' => $action->value,
            'direction' => 'inbound',
            'email' => $email,
            'status' => 'success',
        ]);
    }
}
