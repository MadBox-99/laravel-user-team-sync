<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsInboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\TeamCreatedFromSync;
use Madbox99\UserTeamSync\Events\TeamUpdatedFromSync;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Receiver\Http\Requests\CreateTeamRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\GetUserTeamsRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\UpdateTeamRequest;

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

    /**
     * Applies a team rename from the publisher.
     *
     * Identity comes from the uuid. The original slug is only a fallback for
     * teams that predate the uuid backfill, and only when the local team has no
     * uuid of its own — a local uuid that differs means the two sides disagree
     * about which team this is, and renaming then would retarget the mapping
     * onto the wrong team. That is reported for a human instead.
     */
    public function update(UpdateTeamRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        $uuid = $validated['uuid'] ?? null;

        $team = $uuid !== null
            ? $teamModel::query()->where('uuid', $uuid)->first()
            : null;

        $adoptUuid = false;

        if (! $team) {
            $candidate = $teamModel::query()->where('slug', $validated['original_slug'])->first();

            if (! $candidate) {
                return response()->json(['message' => 'Team not found'], 404);
            }

            if ($candidate->getAttribute('uuid') !== null) {
                return response()->json([
                    'message' => 'Team uuid mismatch',
                    'local_uuid' => $candidate->getAttribute('uuid'),
                    'incoming_uuid' => $uuid,
                ], 409);
            }

            $team = $candidate;
            // Same rule identity:push-uuids applies: fill an empty uuid, never
            // overwrite a differing one. Healing it here keeps the next rename
            // off the fallback path.
            $adoptUuid = $uuid !== null;
        }

        if (isset($validated['slug']) && $validated['slug'] !== $team->slug) {
            $taken = $teamModel::query()
                ->where('slug', $validated['slug'])
                ->where($team->getKeyName(), '!=', $team->getKey())
                ->exists();

            if ($taken) {
                return response()->json(['message' => 'Slug already taken by another team'], 409);
            }
        }

        $changedData = array_intersect_key($validated, array_flip(['name', 'slug']));

        $attributes = $changedData;

        if ($adoptUuid) {
            $attributes['uuid'] = $uuid;
        }

        if ($attributes !== []) {
            // forceFill: receiver Team models are app-owned and every upgraded
            // production receiver omits 'uuid' from $fillable, so mass
            // assignment would report success while discarding it.
            // saveQuietly: never re-trigger this app's own publisher observer.
            $team->forceFill($attributes)->saveQuietly();
        }

        $this->logInbound(SyncAction::UpdateTeam, '');

        Log::info('UserTeamSync: Team updated via sync', [
            'team_id' => $team->getKey(),
            'original_slug' => $validated['original_slug'],
            'fields' => array_keys($changedData),
            'uuid_adopted' => $adoptUuid,
        ]);

        TeamUpdatedFromSync::dispatch($team, $changedData);

        return response()->json(['message' => 'Team updated successfully']);
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
