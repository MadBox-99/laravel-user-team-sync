<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class ValidateSyncApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('user-team-sync.receiver.api_key');

        if (! $expectedKey) {
            Log::warning('UserTeamSync: receiver.api_key is not configured; all sync requests will be rejected with 401.');

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($request->bearerToken() !== $expectedKey) {
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
