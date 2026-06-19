<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the edit-user form. The password is optional on update — leave
 * it blank to keep the existing password. Admins cannot edit their own
 * account through this form (enforced by the policy + middleware).
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user('web');

        if (! $actor || ! $actor->hasRole(User::ROLE_ADMIN)) {
            return false;
        }

        // Admins cannot edit their own role/account from here.
        $target = $this->route('user');

        return $target instanceof User && $actor->id !== $target->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target->id),
            ],
            'role' => ['required', Rule::in([
                User::ROLE_VENDOR,
                User::ROLE_COMPLIANCE_OFFICER,
                User::ROLE_ADMIN,
            ])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
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
        ];
    }

    /**
     * Only include the password in the validated payload when it was
     * actually supplied (so leaving the field blank does not blank the
     * stored hash).
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        if (empty($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    /**
     * Coerce checkbox / boolean fields before validation runs.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
