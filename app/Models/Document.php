<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An individual uploaded file within a submission (see
 * {@see database/migrations/2026_06_07_000004_create_documents_table.php}).
 *
 * `file_path` is the ORIGINAL upload — the forensic Stage T (tampering) reads it
 * directly; `converted_image_path` is the preprocessed page used by OCR /
 * classification. The two must not be confused: preprocessing destroys the
 * compression/metadata signals the forensic layer depends on.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $vendor_id
 * @property int|null $document_type_id
 * @property string $original_filename
 * @property string $file_path
 * @property string|null $converted_image_path
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property int|null $page_number
 * @property string $processing_status
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PREPROCESSING = 'preprocessing';

    public const STATUS_OCR = 'ocr';

    public const STATUS_CLASSIFYING = 'classifying';

    public const STATUS_DETECTING = 'detecting';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_FORENSICS = 'forensics';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'submission_id',
        'vendor_id',
        'document_type_id',
        'original_filename',
        'file_path',
        'converted_image_path',
        'mime_type',
        'file_size_bytes',
        'page_number',
        'processing_status',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The Stage T forensic-tampering result for this document.
     *
     * @return HasOne<TamperAnalysis, $this>
     */
    public function tamperAnalysis(): HasOne
    {
        return $this->hasOne(TamperAnalysis::class);
    }

    /**
     * Scope: documents still awaiting pipeline processing.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('processing_status', self::STATUS_QUEUED);
    }
}
