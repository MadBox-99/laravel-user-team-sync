<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher\Observers;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Madbox99\UserTeamSync\Publisher\PublisherService;

final class UserSyncObserver
{
    public function updated(Model $user): void
    {
        if (app()->bound('user-team-sync.receiving') && app('user-team-sync.receiving')) {
            return;
        }

        $syncFields = config('user-team-sync.publisher.sync_fields', ['email', 'role']);
        $changedData = [];
        $originalEmail = $user->getOriginal('email');

        foreach ($syncFields as $field) {
            if (! $user->wasChanged($field)) {
                continue;
            }

            if ($field === 'email') {
                $changedData['new_email'] = $user->email;
            } else {
                $value = $user->{$field};
                $changedData[$field] = $value instanceof BackedEnum ? $value->value : $value;
            }
        }

        if ($changedData !== []) {
            app(PublisherService::class)->syncUser(
                email: $originalEmail ?? $user->email,
                changedData: $changedData,
            );
        }
    }
}
