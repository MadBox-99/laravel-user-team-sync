<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $email,
        public readonly string $appName,
        public readonly string $action,
        public readonly string $errorMessage,
    ) {}
}
