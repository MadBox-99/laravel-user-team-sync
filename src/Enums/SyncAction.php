<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Enums;

enum SyncAction: string
{
    case CreateUser = 'create_user';
    case SyncUser = 'sync_user';
    case CreateTeam = 'create_team';
    case ToggleActive = 'toggle_active';
    case SyncPassword = 'sync_password';
}
