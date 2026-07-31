<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;

/**
 * Read-only snapshot of this app's identity state, used by the publisher's
 * `identity:audit` command to diff against its own records before the UUID
 * migration. Introduced because no existing endpoint can reveal teams that
 * exist only on the receiver.
 */
final class IdentityAuditController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var class-string<Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        return response()->json([
            'teams' => $teamModel::query()
                ->orderBy('id')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Model $team): array => [
                    'id' => $team->getKey(),
                    'name' => $team->getAttribute('name'),
                    'slug' => $team->getAttribute('slug'),
                ])
                ->values(),

            'users' => $userModel::query()
                ->orderBy('id')
                ->get(['id', 'email'])
                ->map(fn (Model $user): array => [
                    'id' => $user->getKey(),
                    'email' => $user->getAttribute('email'),
                ])
                ->values(),

            'memberships' => DB::table('team_user')
                ->join('users', 'users.id', '=', 'team_user.user_id')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->orderBy('users.email')
                ->orderBy('teams.slug')
                ->get(['users.email as user_email', 'teams.slug as team_slug'])
                ->map(fn (object $row): array => [
                    'user_email' => $row->user_email,
                    'team_slug' => $row->team_slug,
                ])
                ->values(),

            'pending_team_attachments' => PendingTeamAttachment::query()
                ->orderBy('user_email')
                ->get(['user_email', 'team_slug'])
                ->map(fn (PendingTeamAttachment $pending): array => [
                    'user_email' => $pending->user_email,
                    'team_slug' => $pending->team_slug,
                ])
                ->values(),
        ]);
    }
}
