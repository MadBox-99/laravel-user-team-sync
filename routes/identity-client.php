<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The controllers arrive in a later task. Referencing them by name keeps this
// file loadable in the meantime; the string form is replaced with real imports
// once the classes exist.
//
// The explicit '@__invoke' suffix matters: a bare class-name string makes
// Laravel treat the action as an invokable controller and call method_exists()
// on it immediately while the route is being registered (i.e. during boot),
// which throws before the class ever exists. Appending '@__invoke' skips that
// eager check — the class is only resolved lazily when a request dispatches
// to the route, by which point Task 5 will have created it.
Route::middleware('web')->group(function (): void {
    Route::get('/auth/redirect', '\Madbox99\UserTeamSync\Client\Http\Controllers\IdentityRedirectController@__invoke')
        ->name('identity.redirect');
    Route::get('/auth/callback', '\Madbox99\UserTeamSync\Client\Http\Controllers\IdentityCallbackController@__invoke')
        ->name('identity.callback');
});
