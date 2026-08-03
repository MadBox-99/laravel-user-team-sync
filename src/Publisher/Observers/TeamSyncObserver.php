<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Observers;

use Illuminate\Database\Eloquent\Model;
use Madbox99\UserTeamSync\Publisher\PublisherService;

/**
 * Propagates team renames. Without this, a team created on the publisher was
 * matched on receivers by slug forever after, so renaming it on the publisher
 * silently broke the cross-app link.
 *
 * Only `updated` is handled: CreateTeamJob already owns the create path, and
 * firing here as well would send two writes to every receiver per registration.
 */
final class TeamSyncObserver
{
    public function updated(Model $team): void
    {
        if (app()->bound('user-team-sync.receiving') && app('user-team-sync.receiving')) {
            return;
        }

        $syncFields = config('user-team-sync.publisher.team_sync_fields', ['name', 'slug']);

        $changedData = [];

        foreach ($syncFields as $field) {
            if ($team->wasChanged($field)) {
                $changedData[$field] = $team->{$field};
            }
        }

        if ($changedData === []) {
            return;
        }

        // getOriginal() still holds the pre-save values here: Eloquent fires
        // `updated` before finishSave() calls syncOriginal(). The receiver knows
        // the team under that old slug, so it is what identifies it there.
        $originalSlug = $team->getOriginal('slug');

        app(PublisherService::class)->updateTeam(
            uuid: $team->getAttribute('uuid'),
            originalSlug: is_string($originalSlug) ? $originalSlug : (string) $team->slug,
            changedData: $changedData,
        );
    }
}
