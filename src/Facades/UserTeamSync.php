<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Facades;

use Illuminate\Support\Facades\Facade;
use Madbox99\UserTeamSync\Publisher\PublisherService;

/**
 * @method static void createUser(string $email, string $name, string $password, string $role, string $ownerEmail)
 * @method static void syncUser(string $email, array $changedData)
 * @method static void createTeam(string $teamName, string $userEmail, ?string $slug = null, ?string $userName = null)
 * @method static void toggleUserActive(string $userEmail, bool $isActive, string $appKey)
 * @method static array getApps()
 * @method static array getActiveApps()
 *
 * @see PublisherService
 */
final class UserTeamSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PublisherService::class;
    }
}
