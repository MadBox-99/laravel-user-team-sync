<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Madbox99\UserTeamSync\Receiver\Http\Requests\StoreIdentityUuidsRequest;

/**
 * Bulk-applies the publisher's UUID mapping to pre-existing rows on this app.
 *
 * Matches users by email and teams by slug — the only cross-app keys that
 * exist before UUIDs do. Never overwrites a UUID that is already set to a
 * different value: that means the two sides disagree about identity, which a
 * human has to resolve, so it is reported rather than silently corrected.
 */
final class IdentityUuidController extends Controller
{
    public function __invoke(StoreIdentityUuidsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        /** @var class-string<Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        $users = $this->apply($userModel, 'email', $validated['users']);
        $teams = $this->apply($teamModel, 'slug', $validated['teams']);

        return response()->json([
            'users_updated' => $users['updated'],
            'users_missing' => $users['missing'],
            'users_conflicting' => $users['conflicting'],
            'teams_updated' => $teams['updated'],
            'teams_missing' => $teams['missing'],
            'teams_conflicting' => $teams['conflicting'],
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array{uuid: string, email?: string, slug?: string}>  $rows
     * @return array{updated: int, missing: array<int, string>, conflicting: array<int, string>}
     */
    private function apply(string $modelClass, string $matchColumn, array $rows): array
    {
        $updated = 0;
        $missing = [];
        $conflicting = [];

        foreach ($rows as $row) {
            $key = $row[$matchColumn];

            $record = $modelClass::query()->where($matchColumn, $key)->first();

            if (! $record instanceof Model) {
                $missing[] = $key;

                continue;
            }

            $current = $record->getAttribute('uuid');

            if ($current === $row['uuid']) {
                continue;
            }

            if ($current !== null) {
                $conflicting[] = $key;

                continue;
            }

            $record->forceFill(['uuid' => $row['uuid']])->saveQuietly();
            $updated++;
        }

        return ['updated' => $updated, 'missing' => $missing, 'conflicting' => $conflicting];
    }
}
