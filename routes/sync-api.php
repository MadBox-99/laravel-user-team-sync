<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Madbox99\UserTeamSync\Receiver\Http\Controllers\PasswordSyncController;
use Madbox99\UserTeamSync\Receiver\Http\Controllers\TeamSyncController;
use Madbox99\UserTeamSync\Receiver\Http\Controllers\UserSyncController;
use Madbox99\UserTeamSync\Receiver\Http\Middleware\ValidateSyncApiKey;

Route::prefix(config('user-team-sync.receiver.route_prefix', 'api'))
    ->middleware(array_merge(
        [ValidateSyncApiKey::class],
        config('user-team-sync.receiver.middleware', []),
    ))
    ->group(function (): void {
        Route::post('/create-user', [UserSyncController::class, 'create']);
        Route::post('/sync-user', [UserSyncController::class, 'sync']);
        Route::post('/toggle-user-active', [UserSyncController::class, 'toggleActive']);
        Route::post('/create-team', [TeamSyncController::class, 'create']);
        Route::get('/user-teams', [TeamSyncController::class, 'getUserTeams']);
        Route::post('/sync-password', PasswordSyncController::class);
    });
