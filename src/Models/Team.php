<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'slug',
    ];

    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        return $this->belongsToMany($userModel);
    }
}
