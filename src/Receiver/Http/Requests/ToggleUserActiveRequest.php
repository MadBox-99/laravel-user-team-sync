<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ToggleUserActiveRequest extends FormRequest
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
            // No exists:users rule: an activation toggle may legitimately arrive
            // before the user has been synced to this app. The controller stores
            // it as a pending activation and applies it once the user is created.
            'email' => ['required', 'email'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
