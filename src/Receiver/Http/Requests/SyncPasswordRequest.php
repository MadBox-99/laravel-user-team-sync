<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncPasswordRequest extends FormRequest
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
            'password_hash' => ['required', 'string', 'min:60'],
        ];
    }
}
