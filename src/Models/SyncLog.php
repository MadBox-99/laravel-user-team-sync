<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SyncLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('user-team-sync.logging.table', 'sync_logs');
    }
}
