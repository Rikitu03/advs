<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the create-user form submitted by an admin from the back office.
 *
 * The admin role is implicitly required by the route's `role:admin` middleware
 * + the UserPolicy. We still re-check here to keep validation self-contained.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('web')?->hasRole(User::ROLE_ADMIN) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in([
                User::ROLE_VENDOR,
                User::ROLE_COMPLIANCE_OFFICER,
                User::ROLE_ADMIN,
            ])],
            'password' => ['required', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'The selected role must be vendor, compliance officer, or admin.',
            'email.unique' => 'A user with this email already exists.',
        ];
    }

    /**
     * Prepare the data for validation (coerce checkbox values).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
