<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;

final class PendingUserActivation extends Model
{
    protected $fillable = [
        'user_email',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
