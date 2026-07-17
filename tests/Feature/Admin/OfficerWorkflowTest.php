<?php

namespace Tests\Feature\Admin;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OfficerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
    }

    /**
     * A pending-review submission for a named company with one completed,
     * scored document.
     */
    private function makePendingSubmission(string $company, float $risk, string $level, array $flags = []): Submission
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create(['company_name' => $company]);

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
            'composite_risk_score' => $risk,
            'risk_level' => $level,
        ]);

        $document = Document::factory()->for($submission)->for($vendor)->create([
            'processing_status' => Document::STATUS_COMPLETED,
        ]);

        ValidationResult::factory()->create([
            'document_id' => $document->id,
            'submission_id' => $submission->id,
            'document_risk_score' => $risk,
            'flags' => $flags,
        ]);

        return $submission;
    }

    public function test_every_officer_tab_renders(): void
    {
        $submission = $this->makePendingSubmission('Santos Trading Corp.', 78.0, 'high');

        $this->actingAs($this->officer);

        foreach ([
            route('admin.dashboard'),
            route('admin.pending'),
            route('admin.archived'),
            route('admin.vendors'),
            route('admin.risk-logs'),
            route('admin.notifications'),
            route('admin.submissions.show', $submission->id),
            route('admin.vendors.show', $submission->vendor_id),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_vendor_cannot_access_officer_tabs(): void
    {
        $vendor = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($vendor)->get(route('admin.pending'))->assertForbidden();
        $this->actingAs($vendor)->get(route('admin.submissions.show', 1))->assertForbidden();
    }

    public function test_unknown_submission_returns_404(): void
    {
        $this->actingAs($this->officer)->get(route('admin.submissions.show', 9999))->assertNotFound();
    }

    public function test_officer_can_approve_a_submission(): void
    {
        $submission = $this->makePendingSubmission('Garcia Textiles', 22.0, 'low');

        $this->actingAs($this->officer);

        Volt::test('admin.submissions.show', ['submission' => (string) $submission->id])
            ->call('startDecision', 'approve')
            ->set('comments', 'Verified against enrolled references.')
            ->call('submitDecision')
            ->assertSee('Approved')
            ->assertSee('Verified against enrolled references.');

        $fresh = $submission->fresh();
        $this->assertSame(Submission::STATUS_APPROVED, $fresh->status);
        $this->assertSame($this->officer->id, $fresh->reviewed_by);
        $this->assertSame('Verified against enrolled references.', $fresh->review_comments);

        // The decision cascades to the vendor, is audited, and notifies the vendor.
        $this->assertSame(Vendor::STATUS_APPROVED, $submission->vendor->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->officer->id,
            'action' => 'submission.approved',
            'entity_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $submission->vendor->user_id,
            'type' => Notification::TYPE_DECISION_MADE,
            'related_submission_id' => $submission->id,
        ]);

        // The deciding officer (and the rest of the officer team) is notified too.
        $officerNotification = Notification::where('user_id', $this->officer->id)
            ->where('type', Notification::TYPE_DECISION_MADE)
            ->where('related_submission_id', $submission->id)
            ->firstOrFail();
        $this->assertSame('Submission approved', $officerNotification->subject);
        $this->assertStringContainsString($this->officer->name, $officerNotification->body);
        $this->assertStringContainsString('Garcia Textiles', $officerNotification->body);
    }

    public function test_risk_breakdown_can_be_filtered_by_document_type(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $birTypeId = (int) DB::table('document_types')->where('name', 'BIR Certificate of Registration')->value('id');
        $permitTypeId = (int) DB::table('document_types')->where('name', 'Business Permit')->value('id');

        $submission = $this->makePendingSubmission('Santos Trading Corp.', 55.0, 'medium');
        $vendor = $submission->vendor;

        // Replace the helper's untyped document with one per document type.
        $submission->documents()->delete();

        $bir = Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $birTypeId,
            'processing_status' => Document::STATUS_COMPLETED,
        ]);
        ValidationResult::factory()->create([
            'document_id' => $bir->id,
            'submission_id' => $submission->id,
            'classification_label' => 'BIR Certificate of Registration',
            'classification_confidence' => 0.97,
            'document_risk_score' => 20.0,
        ]);

        $permit = Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $permitTypeId,
            'processing_status' => Document::STATUS_COMPLETED,
        ]);
        ValidationResult::factory()->create([
            'document_id' => $permit->id,
            'submission_id' => $submission->id,
            'classification_label' => 'Business Permit',
            'classification_confidence' => 0.71,
            'document_risk_score' => 90.0,
        ]);

        $this->actingAs($this->officer);

        // Default ('All') shows the highest-risk document — the Business Permit.
        $component = Volt::test('admin.submissions.show', ['submission' => (string) $submission->id])
            ->assertSet('componentFilter', 'all')
            ->assertSee('Classified as Business Permit')
            ->assertSee('71% conf.');

        // Filtering to the BIR type swaps in that document's component scores.
        $component->call('setComponentFilter', 'type-'.$birTypeId)
            ->assertSee('Classified as BIR Certificate of Registration')
            ->assertSee('97% conf.')
            ->assertDontSee('Classified as Business Permit');

        // Unknown filter keys are refused.
        $component->call('setComponentFilter', 'type-999999')->assertStatus(400);
    }

    public function test_officer_can_request_resubmission_with_a_reason(): void
    {
        $submission = $this->makePendingSubmission('Tan Imports', 84.0, 'high');

        $this->actingAs($this->officer);

        Volt::test('admin.submissions.show', ['submission' => (string) $submission->id])
            ->call('startDecision', 'resubmit')
            ->set('comments', 'Forged stamp suspected.')
            ->call('submitDecision')
            ->assertSee('Resubmission requested');

        $fresh = $submission->fresh();
        $this->assertSame(Submission::STATUS_RESUBMISSION_REQUESTED, $fresh->status);
        $this->assertSame(Vendor::STATUS_REJECTED, $submission->vendor->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->officer->id,
            'action' => 'submission.resubmission_requested',
            'entity_id' => $submission->id,
        ]);

        $notification = Notification::where('user_id', $submission->vendor->user_id)->firstOrFail();
        $this->assertSame('Resubmission requested', $notification->subject);
        $this->assertStringContainsString('resubmission', $notification->body);
        $this->assertStringContainsString('Forged stamp suspected.', $notification->body);

        $officerNotification = Notification::where('user_id', $this->officer->id)
            ->where('type', Notification::TYPE_DECISION_MADE)
            ->where('related_submission_id', $submission->id)
            ->firstOrFail();
        $this->assertSame('Resubmission requested', $officerNotification->subject);
        $this->assertStringContainsString($this->officer->name, $officerNotification->body);
        $this->assertStringContainsString('Tan Imports', $officerNotification->body);
        $this->assertStringContainsString('Forged stamp suspected.', $officerNotification->body);
    }

    public function test_a_decided_submission_cannot_be_decided_again(): void
    {
        $submission = $this->makePendingSubmission('Cruz Logistics Inc.', 40.0, 'medium');

        $this->actingAs($this->officer);

        $component = Volt::test('admin.submissions.show', ['submission' => (string) $submission->id])
            ->call('startDecision', 'approve')
            ->call('submitDecision');

        $component->call('startDecision', 'resubmit')
            ->call('submitDecision')
            ->assertStatus(400);

        $this->assertSame(Submission::STATUS_APPROVED, $submission->fresh()->status);
    }

    public function test_pending_queue_filters_by_search_and_risk(): void
    {
        $this->makePendingSubmission('Santos Trading Corp.', 78.0, 'high');
        $this->makePendingSubmission('Mendoza Pharma', 12.0, 'low');

        $this->actingAs($this->officer);

        Volt::test('admin.pending')
            ->assertSee('Santos Trading Corp.')
            ->assertSee('Mendoza Pharma')
            ->set('search', 'Santos')
            ->assertSee('Santos Trading Corp.')
            ->assertDontSee('Mendoza Pharma')
            ->set('search', '')
            ->call('setRisk', 'low')
            ->assertSee('Mendoza Pharma')
            ->assertDontSee('Santos Trading Corp.');
    }

    public function test_archived_reports_filter_by_decision(): void
    {
        $approved = $this->makePendingSubmission('Garcia Textiles', 15.0, 'low');
        $resubmission = $this->makePendingSubmission('Tan Imports', 84.0, 'high');

        $approved->update(['status' => Submission::STATUS_APPROVED, 'reviewed_by' => $this->officer->id, 'reviewed_at' => now()]);
        $resubmission->update(['status' => Submission::STATUS_RESUBMISSION_REQUESTED, 'reviewed_by' => $this->officer->id, 'reviewed_at' => now()]);

        $this->actingAs($this->officer);

        Volt::test('admin.archived')
            ->call('setDecision', 'resubmission_requested')
            ->assertSee('Tan Imports')
            ->assertDontSee('Garcia Textiles');
    }

    public function test_notifications_can_be_marked_read(): void
    {
        Notification::factory()->count(3)->create(['user_id' => $this->officer->id]);

        $this->actingAs($this->officer);

        $this->assertSame(3, $this->officer->notifications()->unread()->count());

        Volt::test('admin.notifications')
            ->call('markRead', $this->officer->notifications()->first()->id)
            ->call('markAllRead');

        $this->assertSame(0, $this->officer->notifications()->unread()->count());
    }

    public function test_dashboard_kpis_count_real_submissions(): void
    {
        $this->makePendingSubmission('Santos Trading Corp.', 78.0, 'high');
        $this->makePendingSubmission('Mendoza Pharma', 12.0, 'low');

        $this->actingAs($this->officer);

        Volt::test('admin.dashboard')
            ->assertSee('Santos Trading Corp.')
            ->assertSee('Pending review');
    }

    public function test_risk_logs_list_flags_from_validation_results(): void
    {
        $this->makePendingSubmission('Santos Trading Corp.', 78.0, 'high', [
            'Document tampering suspected',
            'Signature verification unavailable',
        ]);

        $this->actingAs($this->officer);

        Volt::test('admin.risk-logs')
            ->assertSee('Document tampering suspected')
            ->assertSee('Signature verification unavailable')
            ->call('setType', 'tampering')
            ->assertSee('Document tampering suspected')
            ->assertDontSee('Signature verification unavailable');
    }
}
