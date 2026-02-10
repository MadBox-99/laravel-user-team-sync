<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateSyncApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('user-team-sync.receiver.api_key');

        if (! $expectedKey || $request->bearerToken() !== $expectedKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        app()->instance('user-team-sync.receiving', true);

        try {
            return $next($request);
        } finally {
            app()->instance('user-team-sync.receiving', false);
        }
    }
}
