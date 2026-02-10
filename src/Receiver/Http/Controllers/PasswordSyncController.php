<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Madbox99\UserTeamSync\Concerns\LogsInboundSync;
use Madbox99\UserTeamSync\Enums\SyncAction;
use Madbox99\UserTeamSync\Events\PasswordSynced;
use Madbox99\UserTeamSync\Receiver\Http\Requests\SyncPasswordRequest;

final class PasswordSyncController extends Controller
{
    use LogsInboundSync;

    public function __invoke(SyncPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $userModel::query()
            ->where('email', $validated['email'])
            ->update(['password' => $validated['password_hash']]);

        $this->logInbound(SyncAction::SyncPassword, $validated['email']);

        PasswordSynced::dispatch($validated['email']);

        return response()->json(['message' => 'Password synced successfully']);
    }
}
