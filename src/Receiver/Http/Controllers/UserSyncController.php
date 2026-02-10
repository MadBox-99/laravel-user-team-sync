<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Concerns\LogsInboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\UserActiveToggled;
use Madbox99\UserTeamSync\Events\UserCreatedFromSync;
use Madbox99\UserTeamSync\Events\UserSynced;
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

        $user = $userModel::query()->create([
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

        $user = $userModel::query()->where('email', $validated['email'])->first();

        $updateData = [];

        if (isset($validated['new_email'])) {
            $updateData['email'] = $validated['new_email'];
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

        $userModel::query()->where('email', $validated['email'])
            ->update(['is_active' => $validated['is_active']]);

        $this->logInbound(SyncAction::ToggleActive, $validated['email']);

        UserActiveToggled::dispatch($validated['email'], $validated['is_active']);

        return response()->json(['message' => 'User updated']);
    }
}
