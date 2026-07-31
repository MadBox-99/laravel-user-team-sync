<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIdentityUuidsRequest extends FormRequest
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
            'users' => ['present', 'array'],
            'users.*.email' => ['required', 'email'],
            'users.*.uuid' => ['required', 'uuid'],
            'teams' => ['present', 'array'],
            'teams.*.slug' => ['required', 'string'],
            'teams.*.uuid' => ['required', 'uuid'],
        ];
    }
}
