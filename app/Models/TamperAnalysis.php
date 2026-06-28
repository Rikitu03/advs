<?php

namespace App\Models;

use App\Services\Document\TamperDetectionService;
use Database\Factories\TamperAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage T forensic-tampering output for a single document (see
 * {@see database/migrations/2026_06_11_000001_create_tamper_analyses_table.php}).
 *
 * Populated from {@see TamperDetectionService}, which runs
 * the five `python/forensics` techniques over the ORIGINAL upload. Scores follow
 * the forensics convention: `tamper_score` is 0 (clean) .. 1 (tampered) and
 * `tamper_authenticity` is its inverse, ready to feed the §6 risk formula as
 * `w5 * (1 - tamper_authenticity)`.
 *
 * @property int $id
 * @property int $document_id
 * @property int $submission_id
 * @property float|null $tamper_score
 * @property float|null $tamper_authenticity
 * @property float|null $tamper_confidence
 * @property bool $hard_flag
 * @property bool|null $tamper_passed
 * @property array<string, mixed>|null $metadata_result
 * @property array<string, mixed>|null $ela_result
 * @property array<string, mixed>|null $copy_move_result
 * @property array<string, mixed>|null $font_result
 * @property array<string, mixed>|null $cross_reference_result
 * @property array<int, string>|null $flags
 */
class TamperAnalysis extends Model
{
    /** @use HasFactory<TamperAnalysisFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'submission_id',
        'tamper_score',
        'tamper_authenticity',
        'tamper_confidence',
        'hard_flag',
        'tamper_passed',
        'metadata_result',
        'ela_result',
        'copy_move_result',
        'font_result',
        'cross_reference_result',
        'flags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tamper_score' => 'float',
            'tamper_authenticity' => 'float',
            'tamper_confidence' => 'float',
            'hard_flag' => 'boolean',
            'tamper_passed' => 'boolean',
            'metadata_result' => 'array',
            'ela_result' => 'array',
            'copy_move_result' => 'array',
            'font_result' => 'array',
            'cross_reference_result' => 'array',
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
