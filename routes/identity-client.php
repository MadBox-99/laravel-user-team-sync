<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Madbox99\UserTeamSync\Client\Http\Controllers\IdentityCallbackController;
use Madbox99\UserTeamSync\Client\Http\Controllers\IdentityRedirectController;

Route::middleware('web')->group(function (): void {
    Route::get('/auth/redirect', IdentityRedirectController::class)->name('identity.redirect');
    Route::get('/auth/callback', IdentityCallbackController::class)->name('identity.callback');
});
