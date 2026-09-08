<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Support\SubmissionPresenter;
use Illuminate\Support\Collection;

/**
 * Writes the §7 in-app notification rows (ADVS_System_Reference.md — event →
 * recipient table). In-app only for now; the email layer is a future,
 * configurable addition.
 */
class NotificationService
{
    /**
     * Document uploaded → vendor: received + processing.
     */
    public function submissionReceived(Submission $submission): void
    {
        $vendorUser = $submission->vendor?->user;

        if ($vendorUser === null) {
            return;
        }

        Notification::create([
            'user_id' => $vendorUser->id,
            'type' => Notification::TYPE_SUBMISSION_RECEIVED,
            'subject' => 'Submission received',
            'body' => 'Your submission has been received and is being processed.',
            'related_submission_id' => $submission->id,
        ]);
    }

    /**
     * Processing complete → vendor; flagged / high-risk alert → officers.
     *
     * @param  list<string>  $flags  Distinct flags raised across the submission's documents.
     */
    public function submissionProcessed(Submission $submission, array $flags = []): void
    {
        $vendorUser = $submission->vendor?->user;
        $company = $submission->vendor?->company_name ?? 'a vendor';
        $flagLabels = array_map(SubmissionPresenter::flagLabel(...), $flags);

        if ($vendorUser !== null) {
            Notification::create([
                'user_id' => $vendorUser->id,
                'type' => Notification::TYPE_PROCESSING_COMPLETE,
                'subject' => 'Processing complete',
                'body' => 'Your submission has been processed and is awaiting review.',
                'related_submission_id' => $submission->id,
            ]);
        }

        $isHighRisk = $submission->risk_level === 'high';
        [$type, $subject, $body] = match (true) {
            $isHighRisk => [
                Notification::TYPE_HIGH_RISK_ALERT,
                'High-risk submission',
                sprintf(
                    'High-risk submission detected (score: %d/100) from %s.',
                    (int) round((float) $submission->composite_risk_score),
                    $company,
                ),
            ],
            $flagLabels !== [] => [
                Notification::TYPE_DOCUMENT_FLAGGED,
                'Submission flagged',
                sprintf('Submission from %s has been flagged: %s.', $company, implode('; ', array_slice($flagLabels, 0, 3))),
            ],
            default => [
                Notification::TYPE_GENERAL,
                'Submission ready for review',
                sprintf('Submission from %s is ready for review.', $company),
            ],
        };

        $this->notifyOfficers($type, $subject, $body, $submission);
    }

    /**
     * Officer decision → vendor (approved / resubmission-requested wording per
     * §7) + a record of the action for the officer team.
     */
    public function decisionMade(Submission $submission): void
    {
        $vendorUser = $submission->vendor?->user;
        $approved = $submission->status === Submission::STATUS_APPROVED;
        $reason = trim((string) $submission->review_comments);

        if ($vendorUser !== null) {
            Notification::create([
                'user_id' => $vendorUser->id,
                'type' => Notification::TYPE_DECISION_MADE,
                'subject' => $approved ? 'Accreditation approved' : 'Resubmission requested',
                'body' => $approved
                    ? 'Your accreditation has been approved.'
                    : 'Your submission requires resubmission — please correct the flagged documents and submit again.'.($reason !== '' ? " Reason: {$reason}" : ''),
                'related_submission_id' => $submission->id,
            ]);
        }

        $officerName = $submission->reviewer?->name ?? 'An officer';
        $company = $submission->vendor?->company_name ?? 'a vendor';

        $this->notifyOfficers(
            Notification::TYPE_DECISION_MADE,
            $approved ? 'Submission approved' : 'Resubmission requested',
            $approved
                ? sprintf('%s approved the submission from %s.', $officerName, $company)
                : sprintf('%s requested resubmission from %s.', $officerName, $company).($reason !== '' ? " Reason: {$reason}" : ''),
            $submission,
        );
    }

    private function notifyOfficers(string $type, string $subject, string $body, Submission $submission): void
    {
        $this->officers()->each(function (User $officer) use ($type, $subject, $body, $submission): void {
            Notification::create([
                'user_id' => $officer->id,
                'type' => $type,
                'subject' => $subject,
                'body' => $body,
                'related_submission_id' => $submission->id,
            ]);
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function officers(): Collection
    {
        return User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_COMPLIANCE_OFFICER])
            ->get();
    }
}
