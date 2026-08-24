<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Policies\SystemSettingPolicy;
use App\Services\SystemSettingsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the batch-update payload submitted from the admin System
 * Settings page. Authorisation is delegated to {@see SystemSettingPolicy}
 * (already enforced by the `role:admin` middleware on the route).
 *
 * The actual per-field rules live in {@see SystemSettingsService::rules()}
 * so the UI and the request stay in sync. The {@see withValidator()} hook
 * also runs the service's coercion so the Volt component never has to
 * call `intval()` / `floatval()` itself.
 */
class UpdateSystemSettingsRequest extends FormRequest
{
    /**
     * Only administrators may update system settings.
     */
    public function authorize(): bool
    {
        return $this->user('web')?->hasRole(User::ROLE_ADMIN) ?? false;
    }

    /**
     * Build the validation rule set by delegating to the service. This keeps
     * the rule list a single source of truth (see {@see SystemSettingsService::rules()}).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var SystemSettingsService $service */
        $service = app(SystemSettingsService::class);

        return $service->rules();
    }

    /**
     * Friendly field labels so error messages refer to "Max file size (MB)"
     * instead of the raw `max_file_size_mb` key.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var SystemSettingsService $service */
        $service = app(SystemSettingsService::class);

        return $service->attributeAliases();
    }

    /**
     * After the standard rules pass, run the service's validator so type
     * coercion + cross-field constraints (e.g. weights summing to ≤ 1.0)
     * are enforced consistently.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $weights = [
                'risk_weight_text',
                'risk_weight_classification',
                'risk_weight_signature',
                'risk_weight_stamp',
                'risk_weight_tamper',
            ];

            $payload = $this->all();
            $sum = 0.0;
            $seen = false;

            foreach ($weights as $key) {
                if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                    $sum += (float) $payload[$key];
                    $seen = true;
                }
            }

            if ($seen && $sum > 1.0001) {
                $v->errors()->add(
                    'risk_weight_text',
                    'The risk-score weights must sum to 1.00 or less (currently '.number_format($sum, 2).').',
                );
            }
        });
    }
}
