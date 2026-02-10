<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;
use Madbox99\UserTeamSync\Events\UserSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Receiver\Http\Requests\CreateUserRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\SyncUserRequest;
use Madbox99\UserTeamSync\Receiver\Http\Requests\ToggleUserActiveRequest;

final class UserSyncController extends Controller
{
    public function create(CreateUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $user = $userModel::query()->create([
            'email' => $validated['email'],
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'is_active' => config('user-team-sync.receiver.default_active', false),
            'email_verified_at' => now(),
        ]);

        $role = $validated['role'] ?? config('user-team-sync.receiver.default_role', 'subscriber');

        if (config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'assignRole')) {
            $user->assignRole($role);
        }

        $teamIds = $validated['team_ids'] ?? [];
        if ($teamIds !== [] && method_exists($user, 'teams')) {
            $user->teams()->sync($teamIds);
        }

        $this->log(SyncAction::CreateUser, $validated['email']);

        Log::info('UserTeamSync: User created via sync', ['email' => $user->email]);

        event(new UserCreatedFromSync($user));

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

        $user = $userModel::query()->where('email', $validated['email'])->firstOrFail();

        $updateData = [];

        if (isset($validated['new_email'])) {
            $updateData['email'] = $validated['new_email'];
        }

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        if ($updateData !== []) {
            $user->fill($updateData);
            $user->saveQuietly();
        }

        if (isset($validated['role']) && config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'syncRoles')) {
            $user->syncRoles([$validated['role']]);
        }

        $this->log(SyncAction::SyncUser, $validated['email']);

        Log::info('UserTeamSync: User synced', ['email' => $validated['email'], 'fields' => array_keys($updateData)]);

        event(new UserSynced($validated['email'], 'inbound', $updateData));

        return response()->json(['message' => 'User synced successfully']);
    }

    public function toggleActive(ToggleUserActiveRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $userModel::query()->where('email', $validated['email'])
            ->update(['is_active' => $validated['is_active']]);

        $this->log(SyncAction::ToggleActive, $validated['email']);

        event(new UserActiveToggled($validated['email'], $validated['is_active']));

        return response()->json(['message' => 'User updated']);
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
