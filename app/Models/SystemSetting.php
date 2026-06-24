<?php

namespace App\Models;

use Database\Factories\SystemSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Key-value store for every tunable threshold and parameter read by the
 * validation pipeline and the admin UI.
 *
 * Backed by the `system_settings` table (see
 * {@see database/migrations/2026_06_07_000009_create_system_settings_table.php}).
 * Mirrors ADVS_System_Reference.md §9 (parameters) and §10 (storage).
 *
 * Each setting has a {@see $key}, a stringified {@see $value}, an optional
 * {@see $description}, and an {@see $updatedBy} FK to the admin who last
 * changed it. Values are stored as strings and coerced to int|float|bool on
 * read through {@see self::typedValue()} and the typed helpers below.
 */
class SystemSetting extends Model
{
    /** @use HasFactory<SystemSettingFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes. `value` is intentionally a string column
     * (storage constraint) — typed reads happen through the helpers below.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'description',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'updated_by' => 'integer',
        ];
    }

    /**
     * Admin who last modified this setting.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Resolve the raw value (string) for a key, falling back to $default if
     * the row is missing. Centralised so callers don't sprinkle
     * `SystemSetting::where('key', ...)->value('value')` everywhere.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        /** @var string|null $value */
        $value = static::query()->where('key', $key)->value('value');

        return $value ?? $default;
    }

    /**
     * Coerce the string value to an int. Returns $default if the row is
     * missing or the value is not numeric.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = static::get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Coerce the string value to a float. Returns $default if the row is
     * missing or the value is not numeric.
     */
    public static function float(string $key, float $default = 0.0): float
    {
        $value = static::get($key);

        return $value !== null && is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Coerce the string value to a boolean. Accepts the standard
     * "1"/"0"/"true"/"false"/"yes"/"no" spellings used by HTML forms.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Persist a setting, recording the admin who made the change and the
     * timestamp. Used by both the UI and the seeder.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function set(string $key, string|int|float|bool $value, ?string $description = null, ?int $updatedBy = null): self
    {
        $payload = [
            'value' => self::stringify($value),
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        if ($updatedBy !== null) {
            $payload['updated_by'] = $updatedBy;
        }

        // updated_at always advances on every save so audit trails stay honest
        // even when the value didn't change.
        $payload['updated_at'] = Carbon::now();

        /** @var self $setting */
        $setting = static::query()->updateOrInsert(
            ['key' => $key],
            $payload,
        );

        return static::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Stringify a typed value for storage.
     */
    public static function stringify(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            // 6 decimal places is enough for similarity/confidence thresholds
            // and keeps the storage column (varchar 500) clean.
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
        }

        return (string) $value;
    }

    /**
     * Every setting the admin UI should render, grouped by category.
     * Each entry declares the input type, default, and validation rules used
     * by both the form renderer and the FormRequest on submit.
     *
     * Mirrors {@see database/seeders/SystemSettingSeeder.php} so the UI
     * reflects every tunable parameter in ADVS_System_Reference.md §9.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function schema(): array
    {
        return [
            'File Upload Constraints' => [
                ['key' => 'max_file_size_mb', 'label' => 'Max file size (MB)', 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 10, 'hint' => 'Per-file upload cap enforced both client- and server-side.'],
                ['key' => 'max_batch_size_mb', 'label' => 'Max batch size (MB)', 'type' => 'int', 'min' => 1, 'max' => 500, 'default' => 50, 'hint' => 'Total size cap across all documents in a single submission.'],
                ['key' => 'accepted_formats', 'label' => 'Accepted formats', 'type' => 'string', 'default' => 'pdf,png,jpg,jpeg', 'hint' => 'Comma-separated extensions (lowercase). Server still re-validates the MIME type.'],
                ['key' => 'max_pdf_pages', 'label' => 'Max PDF pages to extract', 'type' => 'int', 'min' => 1, 'max' => 20, 'default' => 2, 'hint' => 'First N pages of a multi-page PDF are converted at 300 DPI.'],
                ['key' => 'pdf_dpi', 'label' => 'PDF rasterization DPI', 'type' => 'int', 'min' => 72, 'max' => 600, 'default' => 300, 'hint' => 'Higher DPI improves OCR / classification accuracy at the cost of memory.'],
            ],

            'Image Preprocessing' => [
                ['key' => 'resize_dimension', 'label' => 'Resize target (px)', 'type' => 'int', 'min' => 128, 'max' => 2048, 'default' => 512, 'hint' => 'Square resize applied to every image before ResNet-50.'],
                ['key' => 'binarization_threshold', 'label' => 'Binarization threshold (0–255)', 'type' => 'int', 'min' => 0, 'max' => 255, 'default' => 150, 'hint' => 'Grayscale threshold for OpenCV THRESH_BINARY_INV.'],
                ['key' => 'morph_kernel_size', 'label' => 'Morphological kernel size', 'type' => 'int', 'min' => 1, 'max' => 15, 'default' => 2, 'hint' => 'Square kernel used by the morphological opening step.'],
            ],

            'Model Confidence Thresholds' => [
                ['key' => 'classification_confidence_threshold', 'label' => 'Classification confidence (0–1)', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.01, 'default' => 0.70, 'hint' => 'ResNet-50 confidence below this flags the document.'],
                ['key' => 'yolo_detection_confidence', 'label' => 'YOLOv8 detection confidence (0–1)', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.01, 'default' => 0.50, 'hint' => 'Minimum detection confidence for signature/stamp crops.'],
                ['key' => 'signature_distance_threshold', 'label' => 'Signature distance threshold', 'type' => 'float', 'min' => 0, 'max' => 5, 'step' => 0.01, 'default' => 1.20, 'hint' => 'Maximum Euclidean distance for a signature to match its enrollment embedding.'],
                ['key' => 'stamp_similarity_threshold', 'label' => 'Stamp similarity threshold (0–1)', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.01, 'default' => 0.85, 'hint' => 'Minimum cosine similarity for a stamp to match its enrollment vector.'],
            ],

            'Risk Score Composition' => [
                ['key' => 'risk_weight_text', 'label' => 'Weight: text validation', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05, 'default' => 0.25, 'hint' => 'Contribution of OCR/keyword coverage to the composite risk score.'],
                ['key' => 'risk_weight_classification', 'label' => 'Weight: classification', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05, 'default' => 0.25, 'hint' => 'Contribution of ResNet-50 confidence.'],
                ['key' => 'risk_weight_signature', 'label' => 'Weight: signature', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05, 'default' => 0.25, 'hint' => 'Contribution of signature similarity.'],
                ['key' => 'risk_weight_stamp', 'label' => 'Weight: stamp', 'type' => 'float', 'min' => 0, 'max' => 1, 'step' => 0.05, 'default' => 0.25, 'hint' => 'Contribution of stamp similarity.'],
                ['key' => 'missing_component_penalty', 'label' => 'Missing-component penalty', 'type' => 'int', 'min' => 0, 'max' => 100, 'default' => 15, 'hint' => 'Risk points added when a required component (signature / stamp) is missing.'],
            ],

            'Risk Bands & Retention' => [
                ['key' => 'high_risk_threshold', 'label' => 'High-risk threshold', 'type' => 'int', 'min' => 0, 'max' => 100, 'default' => 61, 'hint' => 'Composite score ≥ this value is classified as High Risk.'],
                ['key' => 'medium_risk_threshold', 'label' => 'Medium-risk threshold', 'type' => 'int', 'min' => 0, 'max' => 100, 'default' => 31, 'hint' => 'Composite score ≥ this (and below High) is Medium Risk.'],
                ['key' => 'data_retention_years', 'label' => 'Data retention (years)', 'type' => 'int', 'min' => 1, 'max' => 50, 'default' => 5, 'hint' => 'Documents older than this become eligible for archival.'],
            ],
        ];
    }
}
