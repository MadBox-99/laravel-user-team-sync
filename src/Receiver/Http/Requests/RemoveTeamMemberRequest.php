<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RemoveTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // No exists: rules: the user or the team may legitimately be unknown
            // to this receiver (create-user fans out to every app, the team may
            // never have reached it). The controller answers 404 for those and
            // still drops any pending attachment, so a late create-user cannot
            // resurrect the membership.
            'user_email' => ['required', 'email'],
            'user_uuid' => ['nullable', 'uuid'],
            'team_slug' => ['required', 'string', 'max:255'],
            'team_uuid' => ['nullable', 'uuid'],
        ];
    }
}
