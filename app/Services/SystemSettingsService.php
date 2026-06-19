<?php

namespace App\Services;

use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Application-layer facade over the {@see SystemSetting} key-value table.
 *
 * Centralises three concerns so call sites stay clean:
 *  1. **Type coercion** — values are stored as strings, but readers want
 *     ints/floats/bools. We coerce here.
 *  2. **Validation** — the canonical rule set per key, derived from
 *     {@see SystemSetting::schema()}, lives here so the Volt UI and the
 *     FormRequest both reuse it.
 *  3. **Audit** — every write records the acting admin and the timestamp;
 *     persistence happens in a transaction so partial writes can never
 *     leave the table in an inconsistent state.
 */
class SystemSettingsService
{
    /**
     * Read every setting as typed values, grouped by category (in the order
     * declared by {@see SystemSetting::schema()}). Missing rows are filled
     * with the schema's `default` so callers always get a complete payload.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function all(): array
    {
        $persisted = SystemSetting::query()
            ->get()
            ->keyBy('key');

        return collect(SystemSetting::schema())
            ->map(function (array $rows, string $category) use ($persisted): array {
                return collect($rows)->map(function (array $field) use ($persisted): array {
                    $key = $field['key'];
                    /** @var SystemSetting|null $row */
                    $row = $persisted->get($key);

                    return [
                        'key' => $key,
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'hint' => $field['hint'] ?? null,
                        'min' => $field['min'] ?? null,
                        'max' => $field['max'] ?? null,
                        'step' => $field['step'] ?? null,
                        'default' => $field['default'],
                        'value' => $row !== null
                            ? $this->coerce($field['type'], $row->value)
                            : $this->coerce($field['type'], (string) $field['default']),
                        'updated_at' => $row?->updated_at,
                        'updated_by' => $row?->updated_by,
                        'description' => $row?->description,
                    ];
                })->all();
            })->all();
    }

    /**
     * Flat list of every key/value pair, useful for piping to the Python
     * pipeline or for snapshotting in tests.
     *
     * @return array<string, string>
     */
    public function flat(): array
    {
        return SystemSetting::query()
            ->orderBy('key')
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * Persist a batch of setting updates inside a single transaction.
     *
     * Validation is performed up-front via {@see validate()} so any failure
     * short-circuits before the first write. The acting admin is recorded
     * on every row so the audit trail stays accurate even when a single
     * save() spans multiple keys.
     *
     * @param  array<string, string>  $values  Map of key → value (raw string).
     */
    public function updateMany(array $values, User $actor): Collection
    {
        $coerced = $this->validate($values);

        return DB::transaction(function () use ($coerced, $actor): Collection {
            $now = Carbon::now();
            $rows = collect();

            foreach ($coerced as $key => $value) {
                /** @var SystemSetting $setting */
                $setting = SystemSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => SystemSetting::stringify($value),
                        'updated_by' => $actor->id,
                        'updated_at' => $now,
                    ],
                );

                $rows->push($setting);
            }

            Log::info('System settings updated', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'keys' => array_keys($coerced),
                'count' => $rows->count(),
            ]);

            return $rows;
        });
    }

    /**
     * Restore a single key to its schema default. Useful for the "Reset"
     * button on each row.
     */
    public function reset(string $key, User $actor): SystemSetting
    {
        $default = $this->defaultFor($key);

        if ($default === null) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        /** @var SystemSetting $setting */
        $setting = SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => SystemSetting::stringify($default),
                'updated_by' => $actor->id,
                'updated_at' => Carbon::now(),
            ],
        );

        Log::info('System setting reset to default', [
            'actor_id' => $actor->id,
            'key' => $key,
        ]);

        return $setting;
    }

    /**
     * Validate a key → value map against the schema. Throws a
     * {@see ValidationException} on the first failure,
     * mirroring Laravel FormRequest behaviour so the Volt page can render the
     * error bag natively.
     *
     * The rule set is built from only the keys present in $values, so callers
     * can submit partial updates (one row's worth of changes) without being
     * scolded for not supplying every other setting.
     *
     * @param  array<string, string>  $values
     * @return array<string, string|int|float|bool> Coerced values keyed by schema key.
     */
    public function validate(array $values): array
    {
        $rules = $this->rulesFor($values);
        $attributes = $this->attributeAliases();

        $validator = validator($values, $rules, [], $attributes);

        // Cross-field constraint: risk weights must sum to ≤ 1.00 when every
        // weight is part of the payload. A partial update (e.g. just changing
        // a single threshold) skips this check so admins can edit one field
        // at a time without manually recomputing the others.
        $weights = [
            'risk_weight_text',
            'risk_weight_classification',
            'risk_weight_signature',
            'risk_weight_stamp',
        ];

        if (count(array_intersect($weights, array_keys($values))) === count($weights)) {
            $sum = array_sum(array_map(
                fn (string $key): float => (float) $values[$key],
                $weights,
            ));

            $validator->after(function ($v) use ($sum): void {
                if ($sum > 1.0001) {
                    $v->errors()->add(
                        'risk_weight_text',
                        'The risk-score weights must sum to 1.00 or less (currently '.number_format($sum, 2).').',
                    );
                }
            });
        }

        $validator->validate();

        $out = [];
        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                $key = $field['key'];
                if (! array_key_exists($key, $values)) {
                    continue;
                }
                $out[$key] = $this->coerce($field['type'], (string) $values[$key]);
            }
        }

        return $out;
    }

    /**
     * Build the Laravel validation rules for every known key.
     *
     * Used by {@see UpdateSystemSettingsRequest}
     * so the FormRequest and the service stay perfectly in sync.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                $rules[$field['key']] = $this->rulesForField($field, required: true);
            }
        }

        return $rules;
    }

    /**
     * Build the rule list for a subset of input keys. Unlike {@see rules()},
     * the `required` rule is dropped and unknown keys are silently ignored —
     * this lets callers submit a partial update (only the keys they're
     * changing) without triggering "field is required" errors on the other
     * 18 settings.
     *
     * @param  array<string, string>  $values
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(array $values): array
    {
        $rules = [];

        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                if (! array_key_exists($field['key'], $values)) {
                    continue;
                }
                $rules[$field['key']] = $this->rulesForField($field, required: false);
            }
        }

        return $rules;
    }

    /**
     * Friendly field names so error messages don't say "The max file size
     * mb field must be…" (with the raw key).
     *
     * @return array<string, string>
     */
    public function attributeAliases(): array
    {
        $aliases = [];

        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                $aliases[$field['key']] = $field['label'];
            }
        }

        return $aliases;
    }

    /**
     * Build the rule list for a single schema entry.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    protected function rulesForField(array $field, bool $required = true): array
    {
        $rules = $required ? ['required'] : [];

        switch ($field['type']) {
            case 'int':
                $rules[] = 'integer';
                if (isset($field['min'])) {
                    $rules[] = "min:{$field['min']}";
                }
                if (isset($field['max'])) {
                    $rules[] = "max:{$field['max']}";
                }
                break;

            case 'float':
                $rules[] = 'numeric';
                if (isset($field['min'])) {
                    $rules[] = "min:{$field['min']}";
                }
                if (isset($field['max'])) {
                    $rules[] = "max:{$field['max']}";
                }
                break;

            case 'string':
                $rules[] = 'string';
                $rules[] = 'max:500';
                break;

            default:
                $rules[] = 'string';
                $rules[] = 'max:500';
        }

        return $rules;
    }

    /**
     * Resolve the schema default for a key, or null if the key is unknown.
     */
    public function defaultFor(string $key): string|int|float|null
    {
        foreach (SystemSetting::schema() as $rows) {
            foreach ($rows as $field) {
                if ($field['key'] === $key) {
                    return $field['default'];
                }
            }
        }

        return null;
    }

    /**
     * Coerce a raw string into the schema-declared type.
     */
    public function coerce(string $type, string $value): string|int|float|bool
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }
}
