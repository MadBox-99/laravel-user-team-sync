<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PasswordSynced
{
    use Dispatchable;

    public function __construct(
        public readonly string $email,
    ) {}
}
