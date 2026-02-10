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
            'email' => ['required', 'email', 'exists:users,email'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
