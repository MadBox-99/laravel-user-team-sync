<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A team change was accepted by a receiver app.
 *
 * Teams are identified by slug rather than email, so this deliberately does not
 * reuse UserSynced: jamming a slug into an `email` property would mislead every
 * listener that reads it.
 */
final class TeamSynced
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $appName,
        public readonly array $changedData = [],
    ) {}
}
