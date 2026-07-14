<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\Submission;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Moves a submission from PROCESSING to PENDING_REVIEW once every one of its
 * documents has reached a terminal processing status, rolling the composite
 * risk up from the per-document results (highest-risk document drives the
 * review queue — ADVS_System_Reference.md §5 Stage 5/6) and emitting the §7
 * "processing complete" notifications.
 *
 * Idempotent and concurrency-safe: two document jobs finishing at the same
 * time serialize on the submission row lock, and only the caller that observes
 * the PROCESSING → PENDING_REVIEW transition sends notifications.
 */
class SubmissionFinalizer
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function finalize(Submission $submission): void
    {
        $transitioned = DB::transaction(function () use ($submission): bool {
            $locked = Submission::query()->lockForUpdate()->find($submission->id);

            if ($locked === null || $locked->status !== Submission::STATUS_PROCESSING) {
                return false;
            }

            $hasUnfinishedDocuments = $locked->documents()
                ->whereNotIn('processing_status', [Document::STATUS_COMPLETED, Document::STATUS_FAILED])
                ->exists();

            if ($hasUnfinishedDocuments) {
                return false;
            }

            $max = $locked->documents()
                ->join('validation_results', 'validation_results.document_id', '=', 'documents.id')
                ->max('validation_results.document_risk_score');

            $high = (int) config('advs.risk.high_threshold');
            $medium = (int) config('advs.risk.medium_threshold');

            $locked->update([
                'composite_risk_score' => $max,
                'risk_level' => $max === null ? null : match (true) {
                    (float) $max >= $high => 'high',
                    (float) $max >= $medium => 'medium',
                    default => 'low',
                },
                'status' => Submission::STATUS_PENDING_REVIEW,
            ]);

            return true;
        });

        if (! $transitioned) {
            return;
        }

        $this->notifications->submissionProcessed($submission->fresh(), $this->collectFlags($submission));
    }

    /**
     * Distinct flags raised across the submission's documents, plus a marker
     * when any document could not be processed at all.
     *
     * @return list<string>
     */
    private function collectFlags(Submission $submission): array
    {
        $flags = $submission->documents()
            ->join('validation_results', 'validation_results.document_id', '=', 'documents.id')
            ->pluck('validation_results.flags')
            ->flatMap(fn ($raw): array => is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []))
            ->unique()
            ->values()
            ->all();

        $failedCount = $submission->documents()
            ->where('processing_status', Document::STATUS_FAILED)
            ->count();

        if ($failedCount > 0) {
            $flags[] = "Processing failed for {$failedCount} document(s)";
        }

        return $flags;
    }
}
