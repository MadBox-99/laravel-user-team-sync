<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\PasswordSynced;
use Madbox99\UserTeamSync\Models\SyncLog;
use Madbox99\UserTeamSync\Receiver\Http\Requests\SyncPasswordRequest;

final class PasswordSyncController extends Controller
{
    public function __invoke(SyncPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $userModel::query()
            ->where('email', $validated['email'])
            ->update(['password' => $validated['password_hash']]);

        $this->log(SyncAction::SyncPassword, $validated['email']);

        event(new PasswordSynced($validated['email']));

        return response()->json([
            'success' => true,
            'message' => 'Password synced successfully.',
        ]);
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
