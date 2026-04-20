<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserHasActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && ! $user->getAttribute('is_active')) {
            $redirectUrl = config('user-team-sync.receiver.inactive_redirect_url');

            if ($redirectUrl) {
                return redirect()->away($redirectUrl);
            }

            abort(403, 'Your subscription is not active.');
        }

        return $next($request);
    }

    private function shouldBypass(Request $request): bool
    {
        $patterns = (array) config('user-team-sync.receiver.bypass_route_patterns', [
            'filament.*.auth.logout',
            'logout',
        ]);

        return $patterns !== [] && $request->routeIs(...$patterns);
    }
}
