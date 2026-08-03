<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A receiver app rejected a team change.
 *
 * A receiver that simply does not know the team (404) is not a failure and does
 * not dispatch this — see UpdateTeamJob.
 */
final class TeamSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $slug,
        public readonly string $appName,
        public readonly string $action,
        public readonly string $errorMessage,
    ) {}
}
