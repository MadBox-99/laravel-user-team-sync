<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final class TeamUpdatedFromSync
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function __construct(
        public readonly Model $team,
        public readonly array $changedData = [],
    ) {}
}
