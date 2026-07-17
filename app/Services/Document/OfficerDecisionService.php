<?php

namespace App\Services\Document;

use App\Models\AuditLog;
use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a compliance officer's final call on a submission — approve, or
 * request resubmission (ADVS_System_Reference.md §5 Stage 6): persists the
 * decision, cascades the vendor's accreditation status, writes the audit-trail
 * entry, and notifies the vendor (§7). The ML pipeline never decides — this
 * service is only ever invoked from an authenticated officer/admin action.
 */
class OfficerDecisionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function decide(Submission $submission, User $officer, string $decision, string $comments = ''): Submission
    {
        if (! in_array($decision, [Submission::STATUS_APPROVED, Submission::STATUS_RESUBMISSION_REQUESTED], true)) {
            throw new InvalidArgumentException("Invalid officer decision [{$decision}].");
        }

        DB::transaction(function () use ($submission, $officer, $decision, $comments): void {
            $locked = Submission::query()->lockForUpdate()->findOrFail($submission->id);

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $officer->id,
                'reviewed_at' => now(),
                'review_comments' => trim($comments) !== '' ? trim($comments) : null,
            ]);

            $locked->vendor?->update([
                'status' => $decision === Submission::STATUS_APPROVED
                    ? Vendor::STATUS_APPROVED
                    : Vendor::STATUS_REJECTED,
                // vendors.risk_score is NOT NULL; an unscored submission maps to 0.
                'risk_score' => $locked->composite_risk_score ?? 0,
            ]);

            AuditLog::create([
                'user_id' => $officer->id,
                'action' => $decision === Submission::STATUS_APPROVED
                    ? 'submission.approved'
                    : 'submission.resubmission_requested',
                'entity_type' => Submission::class,
                'entity_id' => $locked->id,
                'details' => [
                    'vendor_id' => $locked->vendor_id,
                    'composite_risk_score' => $locked->composite_risk_score,
                    'risk_level' => $locked->risk_level,
                    'comments' => $locked->review_comments,
                ],
                'ip_address' => request()?->ip(),
            ]);
        });

        $fresh = $submission->fresh(['vendor.user', 'reviewer']);

        $this->notifications->decisionMade($fresh);

        return $fresh;
    }
}
