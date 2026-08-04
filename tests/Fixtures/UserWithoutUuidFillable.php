<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stands in for the host app's own User model on a receiver that has not
 * added 'uuid' to $fillable — the exact condition that made create-user
 * silently discard a supplied uuid before the forceFill() fix. Deliberately
 * kept separate from the default `User` fixture so this precondition can
 * never accidentally drift if that fixture's $fillable changes.
 */
class UserWithoutUuidFillable extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'email_verified_at',
        'role',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    public function teams(): BelongsToMany
    {
        // Explicit pivot table and keys: this model's class basename does not
        // match its table name, so Eloquent's alphabetical defaults (pivot
        // table "team_user_without_uuid_fillable", foreign key
        // "user_without_uuid_fillable_id") would miss the real 'team_user'
        // table and its 'user_id'/'team_id' columns from the package migration.
        return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id');
    }
}
