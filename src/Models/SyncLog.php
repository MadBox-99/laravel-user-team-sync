<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SyncLog extends Model
{
    protected $fillable = [
        'action',
        'direction',
        'target_app',
        'email',
        'payload',
        'status',
        'error_message',
        'http_status',
        'attempt',
    ];

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
