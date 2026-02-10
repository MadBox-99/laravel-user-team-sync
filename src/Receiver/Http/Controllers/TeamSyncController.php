<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\TeamCreatedFromSync;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Receiver\Http\Requests\CreateTeamRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\GetUserTeamsRequest;

final class TeamSyncController extends Controller
{
    public function create(CreateTeamRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $team = $teamModel::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
        ]);

        $userAttached = false;

        if (isset($validated['user_email'])) {
            $user = $userModel::query()->where('email', $validated['user_email'])->first();
            if ($user && method_exists($user, 'teams')) {
                $user->teams()->attach($team);
                $userAttached = true;
            }
        }

        $this->log(SyncAction::CreateTeam, $validated['user_email'] ?? '');

        Log::info('UserTeamSync: Team created via sync', [
            'team_id' => $team->id,
            'name' => $team->name,
            'user_attached' => $userAttached,
        ]);

        event(new TeamCreatedFromSync($team));

        return response()->json([
            'message' => 'Team created successfully',
            'team_id' => $team->id,
        ], 201);
    }

    public function getUserTeams(GetUserTeamsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $user = $userModel::query()->where('email', $validated['user_email'])->first();

        $teams = $user && method_exists($user, 'teams')
            ? $user->teams()->get(['teams.id'])
            : collect();

        return response()->json(['teams' => $teams]);
    }

    private function log(SyncAction $action, string $email): void
    {
        if (! config('user-team-sync.logging.enabled')) {
            return;
        }

        SyncLog::query()->create([
            'action' => $action->value,
            'direction' => 'inbound',
            'email' => $email,
            'status' => 'success',
        ]);
    }
}
