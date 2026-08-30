<?php

namespace App\Models;

use Database\Factories\PipelinePageResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelinePageResult extends Model
{
    /** @use HasFactory<PipelinePageResultFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'pipeline_run_id',
        'document_id',
        'submission_id',
        'page_index',
        'status',
        'stages',
        'flags',
        'timings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page_index' => 'integer',
            'stages' => 'array',
            'flags' => 'array',
            'timings' => 'array',
        ];
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
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
