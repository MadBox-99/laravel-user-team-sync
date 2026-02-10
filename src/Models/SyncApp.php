<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SyncApp extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'api_key' => 'encrypted',
        ];
    }

    public function getTable(): string
    {
        return config('user-team-sync.publisher.apps_table', 'sync_apps');
    }

    /**
     * @return array{url: string, api_key: ?string, active: bool}
     */
    public function toAppArray(): array
    {
        return [
            'url' => $this->url,
            'api_key' => $this->api_key,
            'active' => $this->is_active,
        ];
    }
}
