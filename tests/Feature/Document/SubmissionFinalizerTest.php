<?php

namespace Tests\Feature\Document;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use App\Services\Document\SubmissionFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionFinalizerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubmission(): Submission
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        return Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PROCESSING,
            'composite_risk_score' => null,
            'risk_level' => null,
        ]);
    }

    private function addDocument(Submission $submission, string $status, ?float $risk = null): Document
    {
        $document = Document::factory()
            ->for($submission)
            ->for($submission->vendor)
            ->create(['processing_status' => $status]);

        if ($risk !== null) {
            ValidationResult::factory()->create([
                'document_id' => $document->id,
                'submission_id' => $submission->id,
                'document_risk_score' => $risk,
                'flags' => [],
            ]);
        }

        return $document;
    }

    public function test_submission_stays_processing_while_documents_are_unfinished(): void
    {
        $submission = $this->makeSubmission();
        $this->addDocument($submission, Document::STATUS_COMPLETED, 12.0);
        $this->addDocument($submission, Document::STATUS_QUEUED);

        app(SubmissionFinalizer::class)->finalize($submission);

        $this->assertSame(Submission::STATUS_PROCESSING, $submission->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_last_terminal_document_moves_submission_to_pending_review_with_max_risk(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
        $submission = $this->makeSubmission();
        $this->addDocument($submission, Document::STATUS_COMPLETED, 12.0);
        $this->addDocument($submission, Document::STATUS_COMPLETED, 47.5);

        app(SubmissionFinalizer::class)->finalize($submission);

        $fresh = $submission->fresh();
        $this->assertSame(Submission::STATUS_PENDING_REVIEW, $fresh->status);
        $this->assertEqualsWithDelta(47.5, (float) $fresh->composite_risk_score, 0.01);
        $this->assertSame('medium', $fresh->risk_level);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $submission->vendor->user_id,
            'type' => Notification::TYPE_PROCESSING_COMPLETE,
            'related_submission_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $officer->id,
            'related_submission_id' => $submission->id,
        ]);
    }

    public function test_finalize_is_idempotent(): void
    {
        $submission = $this->makeSubmission();
        $this->addDocument($submission, Document::STATUS_COMPLETED, 20.0);

        $finalizer = app(SubmissionFinalizer::class);
        $finalizer->finalize($submission);
        $notificationCount = Notification::count();

        $finalizer->finalize($submission->fresh());

        $this->assertSame($notificationCount, Notification::count());
        $this->assertSame(Submission::STATUS_PENDING_REVIEW, $submission->fresh()->status);
    }

    public function test_failed_documents_are_terminal_and_flagged_to_officers(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
        $submission = $this->makeSubmission();
        $this->addDocument($submission, Document::STATUS_COMPLETED, 15.0);
        $this->addDocument($submission, Document::STATUS_FAILED);

        app(SubmissionFinalizer::class)->finalize($submission);

        $this->assertSame(Submission::STATUS_PENDING_REVIEW, $submission->fresh()->status);

        $officerNotification = Notification::where('user_id', $officer->id)->firstOrFail();
        $this->assertSame(Notification::TYPE_DOCUMENT_FLAGGED, $officerNotification->type);
        $this->assertStringContainsString('Processing failed for 1 document(s)', $officerNotification->body);
    }

    public function test_high_risk_submission_sends_high_risk_alert_to_officers(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
        $submission = $this->makeSubmission();
        $this->addDocument($submission, Document::STATUS_COMPLETED, 85.0);

        app(SubmissionFinalizer::class)->finalize($submission);

        $this->assertSame('high', $submission->fresh()->risk_level);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $officer->id,
            'type' => Notification::TYPE_HIGH_RISK_ALERT,
        ]);
    }
}
