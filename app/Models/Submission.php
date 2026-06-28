<?php

namespace App\Models;

use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of one or more documents a vendor submits for accreditation (see
 * {@see database/migrations/2026_06_07_000003_create_submissions_table.php}).
 * Carries the composite risk score the officer review queue is sorted by.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $status
 * @property float|null $composite_risk_score
 * @property string|null $risk_level
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vendor_id',
        'status',
        'composite_risk_score',
        'risk_level',
        'reviewed_by',
        'reviewed_at',
        'review_comments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'composite_risk_score' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
