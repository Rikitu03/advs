<?php

namespace App\Models;

use App\Services\MlModelScanner;
use Database\Factories\MlModelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Managed registration of a single machine-learning weight file used by
 * the ADVS validation pipeline.
 *
 * The row mirrors the file on disk under `python/models/`; it does NOT
 * store the weights themselves (those are gitignored binary blobs — see
 * ADVS_System_Reference.md §5 and CLAUDE.md §6). The panel renders
 * availability + change history by combining this row with a fresh
 * {@see MlModelScanner} run.
 *
 * @property int $id
 * @property string $name Human-readable name (e.g. "Document Classifier")
 * @property string $purpose Pipeline stage: classification | detection | signature | stamp_logo
 * @property string $version SemVer-ish string the panel renders
 * @property string $status active | standby | deprecated | missing
 * @property string $storage_path Relative (under python/models/) or absolute path
 * @property int|null $file_size_bytes Last-known file size in bytes
 * @property string|null $checksum_sha256 SHA-256 of the file contents
 * @property string|null $notes
 * @property array<string, mixed>|null $metrics Model-specific metrics (JSON)
 * @property Carbon|null $last_trained_at Last time the underlying file changed on disk
 * @property Carbon|null $last_synced_at Last time the panel re-checked the file
 * @property int|null $updated_by
 */
class MlModel extends Model
{
    /** @use HasFactory<MlModelFactory> */
    use HasFactory;

    /**
     * Allowed `purpose` values. Mirrored on the column in the migration
     * (kept as a varchar there for forward compatibility) and exposed here
     * so the UI's filter dropdown / form select stays in sync with the
     * canonical list.
     *
     * @return array<int, string>
     */
    public const PURPOSES = [
        'classification',
        'detection',
        'signature',
        'stamp_logo',
    ];

    /**
     * Allowed `status` values. See the migration docblock for the
     * semantic of each.
     *
     * @return array<int, string>
     */
    public const STATUSES = [
        'active',
        'standby',
        'deprecated',
        'missing',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'purpose',
        'version',
        'status',
        'storage_path',
        'file_size_bytes',
        'checksum_sha256',
        'notes',
        'metrics',
        'last_trained_at',
        'last_synced_at',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'metrics' => 'array',
            'last_trained_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'updated_by' => 'integer',
        ];
    }

    /**
     * Admin who last modified the row's configuration (status, notes,
     * version, etc.). Nullable because deleting an admin does not cascade
     * the model's registration away — see the migration's `nullOnDelete`.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: only `active` rows (the model currently driving the pipeline).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: only `missing` rows — the file isn't on disk where the
     * scanner expected it. Useful for the KPI tile + the "needs attention"
     * banner on the panel.
     */
    public function scopeMissing(Builder $query): Builder
    {
        return $query->where('status', 'missing');
    }

    /**
     * Scope: filter by pipeline stage.
     */
    public function scopeForPurpose(Builder $query, ?string $purpose): Builder
    {
        if ($purpose === null || $purpose === '') {
            return $query;
        }

        return $query->where('purpose', $purpose);
    }

    /**
     * Scope: filter by deployment state.
     */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Scope: case-insensitive substring match across name, notes, and the
     * JSON metrics blob. Same LIKE strategy used by {@see AuditLog::scopeSearch()}.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhere('version', 'like', $like)
                ->orWhere('storage_path', 'like', $like)
                ->orWhere('metrics', 'like', $like);
        });
    }

    /**
     * Resolve the file on disk through the scanner's resolver so the UI
     * can render "exists / not found" hints without re-implementing the
     * base-path logic. See {@see MlModelScanner::resolvePath()}.
     */
    public function resolvedPath(): ?string
    {
        return app(MlModelScanner::class)->resolvePath($this->storage_path);
    }

    /**
     * Convenience badge colour resolver for the status pill in the view.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'emerald',
            'standby' => 'sky',
            'deprecated' => 'zinc',
            'missing' => 'rose',
            default => 'zinc',
        };
    }

    /**
     * Convenience label resolver for the status pill text.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'standby' => 'Standby',
            'deprecated' => 'Deprecated',
            'missing' => 'Missing',
            default => ucfirst((string) $this->status),
        };
    }
}
