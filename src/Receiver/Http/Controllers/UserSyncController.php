<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsInboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;
use Madbox99\UserTeamSync\Models\PendingUserActivation;
use Madbox99\UserTeamSync\Receiver\Http\Requests\CreateUserRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\SyncUserRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\ToggleUserActiveRequest;

final class UserSyncController extends Controller
{
    use LogsInboundSync;

    public function create(CreateUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $user = DB::transaction(function () use ($userModel, $validated) {
            $user = $userModel::query()->create([
                'uuid' => $validated['uuid'] ?? null,
                'email' => $validated['email'],
                'name' => $validated['name'],
                'password' => '',
                'is_active' => config('user-team-sync.receiver.default_active', false),
                'email_verified_at' => now(),
            ]);

            // Bypass model casts to avoid double-hashing pre-hashed password
            $userModel::query()->where($user->getKeyName(), $user->getKey())
                ->update(['password' => $validated['password_hash']]);

            $role = $validated['role'] ?? config('user-team-sync.receiver.default_role', 'subscriber');

            if (config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'assignRole')) {
                $user->assignRole($role);
            } else {
                $userModel::query()->where($user->getKeyName(), $user->getKey())
                    ->update(['role' => $role]);
            }

            $teamIds = $validated['team_ids'] ?? [];
            if ($teamIds !== [] && method_exists($user, 'teams')) {
                $user->teams()->sync($teamIds);
            }

            $this->attachBySlugs($user, $validated['team_slugs'] ?? []);
            $this->consumePendingAttachments($user);
            $this->consumePendingActivation($user);

            return $user;
        });

        $this->logInbound(SyncAction::CreateUser, $validated['email']);

        Log::info('UserTeamSync: User created via sync', ['email' => $user->email]);

        UserCreatedFromSync::dispatch($user);

        return response()->json([
            'message' => 'User created successfully',
            'user_id' => $user->id,
        ], 201);
    }

    public function sync(SyncUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $updateData = isset($validated['new_email']) ? ['email' => $validated['new_email']] : [];

        DB::transaction(function () use ($userModel, $validated, $updateData): void {
            $user = $userModel::query()->where('email', $validated['email'])->lockForUpdate()->first();

            if (! $user) {
                abort(404, 'User not found');
            }

            if ($updateData !== []) {
                $user->fill($updateData);
                $user->saveQuietly();
            }

            // Bypass model casts to avoid double-hashing pre-hashed password
            if (isset($validated['password_hash'])) {
                $userModel::query()->where($user->getKeyName(), $user->getKey())
                    ->update(['password' => $validated['password_hash']]);
            }

            if (isset($validated['role'])) {
                if (config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'syncRoles')) {
                    $user->syncRoles([$validated['role']]);
                } else {
                    $userModel::query()->where($user->getKeyName(), $user->getKey())
                        ->update(['role' => $validated['role']]);
                }
            }
        });

        $this->logInbound(SyncAction::SyncUser, $validated['email']);

        Log::info('UserTeamSync: User synced', ['email' => $validated['email'], 'fields' => array_keys($updateData)]);

        UserSynced::dispatch($validated['email'], 'inbound', $updateData);

        return response()->json(['message' => 'User synced successfully']);
    }

    public function toggleActive(ToggleUserActiveRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $affected = $userModel::query()->where('email', $validated['email'])
            ->update(['is_active' => $validated['is_active']]);

        // The user may not have been synced to this app yet (the activation
        // toggle can arrive before the create-user event). Persist the desired
        // state so create() can apply it once the user shows up — otherwise the
        // update matches zero rows and the activation is silently lost.
        if ($affected === 0) {
            PendingUserActivation::query()->updateOrCreate(
                ['user_email' => $validated['email']],
                ['is_active' => $validated['is_active']],
            );
        }

        $this->logInbound(SyncAction::ToggleActive, $validated['email']);

        UserActiveToggled::dispatch($validated['email'], $validated['is_active']);

        return response()->json(['message' => 'User updated']);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function attachBySlugs(Model $user, array $slugs): void
    {
        if ($slugs === [] || ! method_exists($user, 'teams')) {
            return;
        }

        /** @var class-string<Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        $localTeams = $teamModel::query()->whereIn('slug', $slugs)->get();

        if ($localTeams->isNotEmpty()) {
            $user->teams()->syncWithoutDetaching($localTeams->pluck('id')->all());
        }

        $existingSlugs = $localTeams->pluck('slug')->all();
        $missingSlugs = array_diff($slugs, $existingSlugs);

        foreach ($missingSlugs as $slug) {
            PendingTeamAttachment::query()->firstOrCreate([
                'user_email' => $user->email,
                'team_slug' => $slug,
            ]);
        }
    }

    private function consumePendingAttachments(Model $user): void
    {
        if (! method_exists($user, 'teams')) {
            return;
        }

        $pending = PendingTeamAttachment::query()
            ->where('user_email', $user->email)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        /** @var class-string<Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        $teams = $teamModel::query()
            ->whereIn('slug', $pending->pluck('team_slug')->all())
            ->get()
            ->keyBy('slug');

        $attachedIds = [];
        foreach ($pending as $p) {
            $team = $teams->get($p->team_slug);
            if (! $team) {
                continue;
            }
            $attachedIds[] = $team->getKey();
            $p->delete();
        }

        if ($attachedIds !== []) {
            $user->teams()->syncWithoutDetaching($attachedIds);
        }
    }

    /**
     * Apply an activation state that arrived before this user was created (see
     * toggleActive()), then discard the pending record so it is applied once.
     */
    private function consumePendingActivation(Model $user): void
    {
        $pending = PendingUserActivation::query()
            ->where('user_email', $user->email)
            ->first();

        if (! $pending instanceof PendingUserActivation) {
            return;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $userModel::query()->where($user->getKeyName(), $user->getKey())
            ->update(['is_active' => $pending->is_active]);

        $pending->delete();
    }
}
