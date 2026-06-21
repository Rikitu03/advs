<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the destructive delete-user form. Requires the admin to type the
 * user's email address as a confirmation guard against misclicks, and
 * verifies the policy in the request lifecycle.
 */
class DestroyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('web');
        $target = $this->route('user');

        if (! $actor instanceof User || ! $target instanceof User) {
            return false;
        }

        // Reuse the policy so the same "last admin" + self-delete guards
        // apply here as in Gate::authorize() callers.
        return $actor->can('delete', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'confirm_email' => ['required', 'string', 'email', 'in:'.$target->email],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_email.in' => 'The confirmation email does not match the user being deleted.',
        ];
    }
}
