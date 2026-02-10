<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password_hash' => ['required', 'string', 'min:60'],
            'role' => ['nullable', 'string'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }
}
