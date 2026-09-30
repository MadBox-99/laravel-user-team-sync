<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A receiver detached a user from a local team because the publisher said the
 * membership ended. The user account itself is left untouched.
 */
final class TeamMemberRemovedFromSync
{
    use Dispatchable;

    public function __construct(
        public readonly Model $user,
        public readonly Model $team,
    ) {}
}
