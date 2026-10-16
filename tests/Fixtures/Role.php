<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for Spatie's Role model: the provisioner only reads its names.
 */
class Role extends Model
{
    protected $guarded = [];
}
