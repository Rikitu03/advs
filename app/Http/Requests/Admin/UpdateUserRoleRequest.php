<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the lightweight "change role only" form used by the row-action
 * dropdown on the user listing. Keeps that endpoint focused so we don't
 * require the full update form just to promote/demote.
 */
class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('web');
        $target = $this->route('user');

        if (! $actor instanceof User || ! $target instanceof User) {
            return false;
        }

        return $actor->hasRole(User::ROLE_ADMIN) && $actor->id !== $target->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in([
                User::ROLE_VENDOR,
                User::ROLE_COMPLIANCE_OFFICER,
                User::ROLE_ADMIN,
            ])],
        ];
    }
}
