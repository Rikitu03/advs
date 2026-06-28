<?php

namespace App\Models;

use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionPolicy extends Model
{
    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory;

    /**
     * Default retention policy definitions used to seed the admin interface.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            [
                'key' => 'submission_records',
                'label' => 'Submission Records',
                'data_type' => 'submission',
                'retention_days' => 1825,
                'archive_enabled' => true,
                'archive_after_days' => 1095,
                'deletion_enabled' => false,
                'deletion_after_days' => null,
                'enabled' => true,
                'rules' => [
                    'trigger' => 'submission_closed',
                    'archive_target' => 'cold_storage',
                ],
                'notes' => 'Covers submission metadata, decisions, and review history.',
            ],
            [
                'key' => 'uploaded_documents',
                'label' => 'Uploaded Documents',
                'data_type' => 'document',
                'retention_days' => 1825,
                'archive_enabled' => true,
                'archive_after_days' => 730,
                'deletion_enabled' => false,
                'deletion_after_days' => null,
                'enabled' => true,
                'rules' => [
                    'preserve_original' => true,
                    'retain_thumbnails' => true,
                ],
                'notes' => 'Includes original uploads and derived preview assets.',
            ],
            [
                'key' => 'audit_logs',
                'label' => 'Audit Logs',
                'data_type' => 'audit',
                'retention_days' => 2555,
                'archive_enabled' => true,
                'archive_after_days' => 1460,
                'deletion_enabled' => false,
                'deletion_after_days' => null,
                'enabled' => true,
                'rules' => [
                    'append_only' => true,
                    'retain_exports' => false,
                ],
                'notes' => 'Retain admin and workflow traces for compliance review.',
            ],
            [
                'key' => 'notifications',
                'label' => 'Notifications',
                'data_type' => 'notification',
                'retention_days' => 365,
                'archive_enabled' => false,
                'archive_after_days' => null,
                'deletion_enabled' => true,
                'deletion_after_days' => 365,
                'enabled' => true,
                'rules' => [
                    'purge_read' => true,
                    'preserve_unread' => true,
                ],
                'notes' => 'Short-lived operational alerts and delivery receipts.',
            ],
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'data_type',
        'retention_days',
        'archive_enabled',
        'archive_after_days',
        'deletion_enabled',
        'deletion_after_days',
        'enabled',
        'rules',
        'notes',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'archive_enabled' => 'boolean',
            'archive_after_days' => 'integer',
            'deletion_enabled' => 'boolean',
            'deletion_after_days' => 'integer',
            'enabled' => 'boolean',
            'rules' => 'array',
            'updated_by' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeForType(Builder $query, ?string $dataType): Builder
    {
        if ($dataType === null || $dataType === '') {
            return $query;
        }

        return $query->where('data_type', $dataType);
    }
}
