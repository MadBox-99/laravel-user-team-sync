<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Guest redirect for client mode: the app's own redirect when it works,
 * otherwise route('login') when it exists, otherwise client.login_url.
 */
final readonly class GuestRedirectFallback
{
    /**
     * @param  (callable(Request): ?string)|null  $previous
     */
    public function __construct(private mixed $previous = null) {}

    public function __invoke(Request $request): ?string
    {
        if (is_callable($this->previous)) {
            try {
                return ($this->previous)($request);
            } catch (RouteNotFoundException) {
                // The app's redirect points at a route this app does not have.
            }
        } elseif (Route::has('login')) {
            return route('login');
        }

        return url((string) config('user-team-sync.client.login_url', '/login'));
    }
}
