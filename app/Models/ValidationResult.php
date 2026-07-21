<?php

namespace App\Models;

use App\Actions\ProcessDocumentAction;
use Database\Factories\ValidationResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ML pipeline output for a single document, one row per document (see
 * {@see database/migrations/2026_06_07_000005_create_validation_results_table.php}).
 *
 * Stages 2–4b populate the component columns; {@see ProcessDocumentAction}
 * fills `document_risk_score` and merges Stage T flags once forensics + risk
 * scoring complete. Component scores are authenticity values in [0,1].
 *
 * @property int $id
 * @property int $document_id
 * @property int $submission_id
 * @property float|null $text_validation_score
 * @property float|null $classification_confidence
 * @property bool|null $signature_detected
 * @property float|null $signature_score
 * @property bool|null $stamp_detected
 * @property float|null $stamp_score
 * @property float|null $document_risk_score
 * @property array<int, string>|null $flags
 */
class ValidationResult extends Model
{
    /** @use HasFactory<ValidationResultFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'submission_id',
        'ocr_extracted_text',
        'ocr_confidence',
        'detected_city',
        'text_validation_score',
        'text_fields_matched',
        'text_fields_expected',
        'classification_label',
        'classification_confidence',
        'signature_detected',
        'signature_bbox',
        'stamp_detected',
        'stamp_bbox',
        'signature_score',
        'signature_distance',
        'signature_passed',
        'stamp_score',
        'stamp_similarity',
        'stamp_passed',
        'stamp_tampered',
        'logo_reference_id',
        'document_risk_score',
        'flags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_confidence' => 'float',
            'text_validation_score' => 'float',
            'classification_confidence' => 'float',
            'signature_detected' => 'boolean',
            'signature_bbox' => 'array',
            'signature_score' => 'float',
            'signature_distance' => 'float',
            'signature_passed' => 'boolean',
            'stamp_detected' => 'boolean',
            'stamp_bbox' => 'array',
            'stamp_score' => 'float',
            'stamp_similarity' => 'float',
            'stamp_passed' => 'boolean',
            'stamp_tampered' => 'boolean',
            'logo_reference_id' => 'integer',
            'document_risk_score' => 'float',
            'flags' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
