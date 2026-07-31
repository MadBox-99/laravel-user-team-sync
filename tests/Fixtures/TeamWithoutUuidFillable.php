<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Stands in for a receiver-supplied Team model (config('user-team-sync.models.team')
 * is configurable, same as the user model) that has not added 'uuid' to
 * $fillable — the exact condition that made create-team silently discard a
 * supplied uuid before the forceFill() fix. Deliberately kept separate from
 * the default `Team` fixture, which does list 'uuid' as fillable for an
 * earlier migration test, so this precondition can never accidentally drift.
 */
class TeamWithoutUuidFillable extends Model
{
    protected $table = 'teams';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
