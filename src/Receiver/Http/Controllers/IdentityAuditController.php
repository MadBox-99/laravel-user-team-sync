<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\Models\PendingTeamAttachment;

/**
 * Read-only snapshot of this app's identity state, used by the publisher's
 * `identity:audit` command to diff against its own records before the UUID
 * migration. Introduced because no existing endpoint can reveal teams that
 * exist only on the receiver.
 *
 * Every entry carries a `uuid` alongside its local id/slug/email so the
 * consumer can match records the same way `IdentityProvisioner` does — by
 * uuid — instead of trusting a slug that may have drifted between the hub
 * and this receiver.
 */
final class IdentityAuditController extends Controller
{
    /**
     * The membership pivot is not business data: it records that a link
     * exists, not a fact about the tenant, so it is never counted even
     * though it does carry a `team_id` column.
     */
    private const string PIVOT_TABLE = 'team_user';

    public function __invoke(Request $request): JsonResponse
    {
        /** @var class-string<Model> $teamModel */
        $teamModel = config('user-team-sync.models.team');

        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $payload = [
            'teams' => $teamModel::query()
                ->orderBy('id')
                ->get(['id', 'uuid', 'name', 'slug'])
                ->map(fn (Model $team): array => [
                    'id' => $team->getKey(),
                    'uuid' => $team->getAttribute('uuid'),
                    'name' => $team->getAttribute('name'),
                    'slug' => $team->getAttribute('slug'),
                ])
                ->values(),

            'users' => $userModel::query()
                ->orderBy('id')
                ->get(['id', 'uuid', 'email'])
                ->map(fn (Model $user): array => [
                    'id' => $user->getKey(),
                    'uuid' => $user->getAttribute('uuid'),
                    'email' => $user->getAttribute('email'),
                ])
                ->values(),

            'memberships' => DB::table(self::PIVOT_TABLE)
                ->join('users', 'users.id', '=', self::PIVOT_TABLE.'.user_id')
                ->join('teams', 'teams.id', '=', self::PIVOT_TABLE.'.team_id')
                ->orderBy('users.email')
                ->orderBy('teams.slug')
                ->get([
                    'users.email as user_email',
                    'users.uuid as user_uuid',
                    'teams.slug as team_slug',
                    'teams.uuid as team_uuid',
                ])
                ->map(fn (object $row): array => [
                    'user_email' => $row->user_email,
                    'user_uuid' => $row->user_uuid,
                    'team_slug' => $row->team_slug,
                    'team_uuid' => $row->team_uuid,
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
        ];

        $requestedSlugs = $this->requestedTeamSlugs($request);

        if ($requestedSlugs !== null) {
            $payload['record_counts'] = $this->countRecordsPerTeam($teamModel, $requestedSlugs);
        }

        return response()->json($payload);
    }

    /**
     * Parses the `count_teams` query parameter into a de-duplicated list of
     * non-empty slugs. Returns null when the parameter was not supplied at
     * all, which is the signal to skip counting entirely — counting across
     * every team_id-bearing table is expensive enough that it must stay
     * opt-in.
     *
     * @return list<string>|null
     */
    private function requestedTeamSlugs(Request $request): ?array
    {
        if (! $request->has('count_teams')) {
            return null;
        }

        $raw = (string) $request->query('count_teams');

        return Collection::make(explode(',', $raw))
            ->map(fn (string $slug): string => trim($slug))
            ->filter(fn (string $slug): bool => $slug !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Counts rows scoped to each requested team across every table this
     * schema uses to link records to a team, excluding the membership pivot.
     * Slugs are always matched with a bound `whereIn`, never interpolated
     * into SQL — the parameter is caller-supplied and must be treated as
     * untrusted even though the caller is authenticated. An unknown slug
     * simply has no team to count against and is left out of the result.
     *
     * @param  class-string<Model>  $teamModel
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    private function countRecordsPerTeam(string $teamModel, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        /** @var Collection<string, Model> $teamsBySlug */
        $teamsBySlug = $teamModel::query()
            ->whereIn('slug', $slugs)
            ->get(['id', 'slug'])
            ->keyBy(fn (Model $team): string => (string) $team->getAttribute('slug'));

        $tables = $this->teamScopedTables();

        $counts = [];

        foreach ($slugs as $slug) {
            $team = $teamsBySlug->get($slug);

            if (! $team instanceof Model) {
                continue;
            }

            $total = 0;

            foreach ($tables as $table) {
                $total += DB::table($table)->where('team_id', $team->getKey())->count();
            }

            $counts[$slug] = $total;
        }

        return $counts;
    }

    /**
     * Discovers every table that carries a `team_id` column by inspecting
     * the live schema, rather than trusting a hard-coded list — receivers
     * have different schemas, and a static list drifted out of date and is
     * exactly what timed out on production once it reached ~30 tables.
     *
     * @return list<string>
     */
    private function teamScopedTables(): array
    {
        return Collection::make(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $table): bool => $table === self::PIVOT_TABLE)
            ->filter(fn (string $table): bool => in_array('team_id', Schema::getColumnListing($table), true))
            ->values()
            ->all();
    }
}
