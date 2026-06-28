<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only compliance audit trail (see
 * {@see database/migrations/2026_06_07_000008_create_audit_logs_table.php}).
 *
 * Every state-changing action taken by an authenticated actor — user CRUD,
 * system-settings edits, officer decisions, etc. — should write a row here.
 * The table has no `updated_at`: rows are immutable by convention and only
 * the `audit_logs` `failed()` job or a manual purge may remove them.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action Short verb-style identifier (e.g. "user.created")
 * @property string|null $entity_type Domain entity class touched by the action
 * @property int|null $entity_id
 * @property array<string, mixed>|null $details Structured before/after + metadata payload
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Audit logs are write-once: only `created_at` exists, and Eloquent must
     * not try to maintain an `updated_at` column.
     *
     * @var string|bool|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'entity_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Actor that performed the action. Nullable so a deleted user keeps their
     * audit rows (the migration uses `nullOnDelete()`).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic handle on the affected entity (e.g. the Vendor, Submission,
     * or SystemSetting that was created / updated / deleted).
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: filter by the actor who performed the action.
     */
    public function scopeByUser(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === null || $userId === '') {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * Scope: filter by the canonical action identifier (e.g. "user.created").
     */
    public function scopeOfAction(Builder $query, ?string $action): Builder
    {
        if ($action === null || $action === '') {
            return $query;
        }

        return $query->where('action', $action);
    }

    /**
     * Scope: filter by the affected entity (polymorphic target).
     */
    public function scopeForEntity(Builder $query, ?string $entityType, int|string|null $entityId): Builder
    {
        if ($entityType === null || $entityType === '') {
            return $query;
        }

        $query->where('entity_type', $entityType);

        if ($entityId !== null && $entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    /**
     * Scope: filter to rows inside an inclusive `[from, to]` date range.
     * Either bound may be null to keep that side open.
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Scope: full-text-ish search over action, entity_type, and the JSON
     * details blob. Uses LIKE for SQLite/MySQL portability.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('action', 'like', $like)
                ->orWhere('entity_type', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('details', 'like', $like);
        });
    }

    /**
     * Canonical list of distinct action values known to the system.
     *
     * Returned to the view so the "Action" filter stays in sync with the
     * constants declared elsewhere (action strings are a free-form string
     * column on purpose — see {@see self::ACTION_*}).
     *
     * @return array<int, string>
     */
    public static function knownActions(): array
    {
        return [
            'auth.login',
            'auth.logout',
            'user.created',
            'user.updated',
            'user.deleted',
            'user.role_changed',
            'system_setting.updated',
            'system_setting.reset',
            'submission.decided',
            'submission.flagged',
            'ml_model.synced',
            'ml_model.missing',
            'ml_model.updated',
        ];
    }

    /**
     * Canonical module names that group related action identifiers in the
     * UI's "Module" filter. Mirrors {@see self::knownActions()}.
     *
     * @return array<string, string>
     */
    public static function modules(): array
    {
        return [
            'Authentication' => 'auth',
            'Users' => 'user',
            'System Settings' => 'system_setting',
            'Submissions' => 'submission',
            'ML Models' => 'ml_model',
        ];
    }
}
