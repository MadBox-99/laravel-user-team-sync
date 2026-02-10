<?php

declare(strict_types=1);

use Madbox99\UserTeamSync\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function authHeaders(): array
{
    return ['Authorization' => 'Bearer test-api-key'];
}
