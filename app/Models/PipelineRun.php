<?php

namespace App\Models;

use Database\Factories\PipelineRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineRun extends Model
{
    /** @use HasFactory<PipelineRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'submission_id',
        'attempt',
        'status',
        'schema_version',
        'settings_snapshot',
        'settings_hash',
        'models',
        'timings',
        'flags',
        'error',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'settings_snapshot' => 'array',
            'models' => 'array',
            'timings' => 'array',
            'flags' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /** @return HasMany<PipelinePageResult, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(PipelinePageResult::class);
    }
}
