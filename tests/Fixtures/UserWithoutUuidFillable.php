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
        return $this->belongsToMany(Team::class);
    }
}
