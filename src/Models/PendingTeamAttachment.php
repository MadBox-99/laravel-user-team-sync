<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;

final class PendingTeamAttachment extends Model
{
    protected $fillable = [
        'user_email',
        'team_slug',
    ];
}
