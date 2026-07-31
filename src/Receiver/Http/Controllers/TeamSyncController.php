<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsInboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\TeamCreatedFromSync;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Receiver\Http\Requests\CreateTeamRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\GetUserTeamsRequest;

final class TeamSyncController extends Controller
{
    use LogsInboundSync;

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

        // Bypass $fillable: a receiver-supplied Team model is not under this
        // package's control, and a model that has not added 'uuid' to
        // $fillable must still persist it rather than silently discarding it
        // while the request reports success.
        if (isset($validated['uuid'])) {
            $team->forceFill(['uuid' => $validated['uuid']])->saveQuietly();
        }

        $userAttached = false;

        if (isset($validated['user_email'])) {
            $user = $userModel::query()->where('email', $validated['user_email'])->first();
            if ($user && method_exists($user, 'teams')) {
                $user->teams()->syncWithoutDetaching([$team->getKey()]);
                $userAttached = true;
            } elseif ($user) {
                Log::warning('UserTeamSync: Team created but user not attached', [
                    'team_id' => $team->id,
                    'user_email' => $validated['user_email'],
                    'reason' => 'user model has no teams() relation',
                ]);
            } else {
                PendingTeamAttachment::query()->firstOrCreate([
                    'user_email' => $validated['user_email'],
                    'team_slug' => $team->slug,
                ]);
            }
        }

        $this->consumePendingAttachmentsForTeam($team, $userModel);

        $this->logInbound(SyncAction::CreateTeam, $validated['user_email'] ?? '');

        Log::info('UserTeamSync: Team created via sync', [
            'team_id' => $team->id,
            'name' => $team->name,
            'user_attached' => $userAttached,
        ]);

        TeamCreatedFromSync::dispatch($team);

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
            ? $user->teams()->pluck('teams.id')->map(fn ($id) => ['id' => $id])
            : collect();

        return response()->json(['teams' => $teams]);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $userModel
     */
    private function consumePendingAttachmentsForTeam(\Illuminate\Database\Eloquent\Model $team, string $userModel): void
    {
        $pending = PendingTeamAttachment::query()
            ->where('team_slug', $team->slug)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $users = $userModel::query()
            ->whereIn('email', $pending->pluck('user_email')->all())
            ->get()
            ->keyBy('email');

        foreach ($pending as $p) {
            $user = $users->get($p->user_email);
            if ($user && method_exists($user, 'teams')) {
                $user->teams()->syncWithoutDetaching([$team->getKey()]);
                $p->delete();
            }
        }
    }
}
