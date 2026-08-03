<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Receiver\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTeamRequest extends FormRequest
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
            'uuid' => ['nullable', 'uuid'],
            'original_slug' => ['required', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            // Deliberately not `unique:teams,slug`: a retried job resends the
            // slug the team already holds, and that must stay a success rather
            // than a validation error. The controller checks for a collision
            // with a *different* team instead.
            'slug' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
