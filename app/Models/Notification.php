<?php

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Targeted, categorized in-app alert for one user (ADVS_System_Reference.md §7).
 *
 * Backed by the custom `notifications` table (migration 2026_06_07_000007) —
 * NOT Laravel's database notification channel; {@see User::notifications()}
 * overrides the Notifiable relation accordingly.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $subject
 * @property string $body
 * @property string $sender
 * @property int|null $related_submission_id
 * @property bool $is_read
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    public const TYPE_SUBMISSION_RECEIVED = 'submission_received';

    public const TYPE_PROCESSING_COMPLETE = 'processing_complete';

    public const TYPE_DOCUMENT_FLAGGED = 'document_flagged';

    public const TYPE_DECISION_MADE = 'decision_made';

    public const TYPE_HIGH_RISK_ALERT = 'high_risk_alert';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_GENERAL = 'general';

    protected $fillable = [
        'user_id',
        'type',
        'subject',
        'body',
        'sender',
        'related_submission_id',
        'is_read',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'related_submission_id');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }
}
