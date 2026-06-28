<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\ValidationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationResult>
 */
class ValidationResultFactory extends Factory
{
    protected $model = ValidationResult::class;

    /**
     * Default state — a clean, fully-detected document (all components pass).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $document = Document::factory();

        return [
            'document_id' => $document,
            'submission_id' => fn (array $attrs): int => Document::find($attrs['document_id'])->submission_id,
            'ocr_extracted_text' => 'BUREAU OF INTERNAL REVENUE\nTIN: 274-118-902-000',
            'ocr_confidence' => 0.94,
            'text_validation_score' => 0.92,
            'text_fields_matched' => 13,
            'text_fields_expected' => 14,
            'classification_label' => 'BIR Permit',
            'classification_confidence' => 0.96,
            'signature_detected' => true,
            'signature_score' => 0.9,
            'signature_passed' => true,
            'stamp_detected' => true,
            'stamp_score' => 0.91,
            'stamp_passed' => true,
            'document_risk_score' => null,
            'flags' => [],
        ];
    }
}
