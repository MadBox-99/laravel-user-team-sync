<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Madbox99\UserTeamSync\Concerns\HasSuperAdmin;

class SuperAdminUser extends User
{
    use HasSuperAdmin;

    protected $table = 'users';
}
