<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

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
