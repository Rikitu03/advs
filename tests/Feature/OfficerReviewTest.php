<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use App\Support\SubmissionPresenter;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficerReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        // Created before seeding so DemoDataSeeder targets this officer with
        // its review notifications.
        $this->officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
    }

    public function test_compliance_officer_can_view_the_pending_submissions_queue(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.pending'))
            ->assertOk()
            ->assertSee('Pending Submissions')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_admin_can_view_the_pending_submissions_queue(): void
    {
        $user = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($user)->get(route('admin.pending'))->assertOk();
    }

    public function test_officer_can_open_a_submission_drill_down(): void
    {
        $this->seed(DemoDataSeeder::class);

        $submission = Submission::query()
            ->where('status', Submission::STATUS_PENDING_REVIEW)
            ->firstOrFail();

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee(SubmissionPresenter::reference($submission))
            ->assertSee('Risk score breakdown')
            ->assertSee('Officer decision');
    }

    public function test_unknown_submission_returns_not_found(): void
    {
        $this->actingAs($this->officer)->get(route('admin.submissions.show', 999999))->assertNotFound();
    }

    public function test_drill_down_lists_exactly_the_submissions_own_documents(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $birTypeId = (int) DB::table('document_types')->where('name', 'BIR Certificate of Registration')->value('id');
        $permitTypeId = (int) DB::table('document_types')->where('name', 'Business Permit')->value('id');

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'northern-star-bir-certificate.png',
            'document_type_id' => $birTypeId,
            'file_size_bytes' => 312_099,
        ]);
        Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'northern-star-business-permit.jpg',
            'document_type_id' => $permitTypeId,
        ]);

        // A document from a different submission must never bleed into this review.
        Document::factory()->create(['original_filename' => 'other-vendor-financial-statement.pdf']);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('Documents (2)')
            ->assertSee('northern-star-bir-certificate.png')
            ->assertSee('BIR Certificate of Registration')
            ->assertSee('northern-star-business-permit.jpg')
            ->assertSee('Business Permit')
            ->assertSee('304.8 KB')
            ->assertDontSee('other-vendor-financial-statement.pdf');
    }

    public function test_drill_down_groups_humanised_flags_by_document(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $birTypeId = (int) DB::table('document_types')->where('name', 'BIR Certificate of Registration')->value('id');

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        $flagged = Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'northern-star-bir-certificate.png',
            'document_type_id' => $birTypeId,
        ]);
        ValidationResult::factory()->for($flagged)->create([
            'flags' => ['missing_required_fields:form_no,tin,rdo_code', 'no_signature_detected'],
        ]);

        // A clean document must not create an empty group in the panel.
        $clean = Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'clean-business-permit.jpg',
            'document_type_id' => $birTypeId,
        ]);
        ValidationResult::factory()->for($clean)->create(['flags' => []]);

        $detail = SubmissionPresenter::detail($submission->fresh());

        $this->assertCount(1, $detail['flags_by_document']);
        $this->assertSame('northern-star-bir-certificate.png', $detail['flags_by_document'][0]['document']);
        $this->assertSame(
            ['Missing required fields: Form No, TIN, RDO Code', 'No signature detected'],
            $detail['flags_by_document'][0]['flags'],
        );

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('Missing required fields: Form No, TIN, RDO Code')
            ->assertSee('No signature detected');
    }

    public function test_flag_humaniser_renders_per_type_field_acronyms(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'flags' => ['missing_required_fields:ocn,trn_no,certificate_no,business_name,tax_types'],
        ]);

        $this->assertSame(
            ['Missing required fields: OCN, TRN, Certificate No, Business Name, Registered Activities'],
            SubmissionPresenter::flags($submission->fresh()),
        );
    }

    public function test_drill_down_provides_per_document_ocr_text_with_filters(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        $bir = Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'bir-certificate.png',
        ]);
        ValidationResult::factory()->for($bir)->create([
            'ocr_extracted_text' => 'BUREAU OF INTERNAL REVENUE',
        ]);

        $permit = Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'business-permit.png',
        ]);
        ValidationResult::factory()->for($permit)->create([
            'ocr_extracted_text' => 'CITY OF DIGOS BUSINESS PERMIT',
        ]);

        $detail = SubmissionPresenter::detail($submission->fresh());

        $this->assertSame(
            [
                ['key' => (string) $bir->id, 'label' => 'bir-certificate.png'],
                ['key' => (string) $permit->id, 'label' => 'business-permit.png'],
            ],
            $detail['ocr_filters'],
        );
        $this->assertSame('BUREAU OF INTERNAL REVENUE', $detail['ocr_by_document'][(string) $bir->id]);
        $this->assertSame('CITY OF DIGOS BUSINESS PERMIT', $detail['ocr_by_document'][(string) $permit->id]);
    }

    public function test_drill_down_ocr_text_falls_back_when_ocr_not_yet_available(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create(['ocr_extracted_text' => null]);

        $detail = SubmissionPresenter::detail($submission->fresh());

        $this->assertSame(
            'OCR stage not yet available — no extracted text for this document.',
            $detail['ocr_by_document'][(string) $document->id],
        );
    }

    public function test_drill_down_marks_each_document_with_a_preview_kind(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'permit.png',
            'mime_type' => 'image/png',
        ]);
        Document::factory()->for($vendor)->for($submission)->create([
            'original_filename' => 'registration.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $documents = collect(SubmissionPresenter::detail($submission->fresh())['documents'])->keyBy('name');

        $this->assertSame('image', $documents['permit.png']['kind']);
        $this->assertSame('pdf', $documents['registration.pdf']['kind']);
    }

    public function test_officer_document_link_streams_the_vendors_stored_file(): void
    {
        Storage::fake('local');
        Storage::put('vendor1/business_permit_northern_star.png', 'stored-vendor-upload-bytes');

        $document = Document::factory()->create([
            'original_filename' => 'Business Permit.png',
            'file_path' => 'vendor1/business_permit_northern_star.png',
            'mime_type' => 'image/png',
        ]);

        $response = $this->actingAs($this->officer)
            ->get(route('admin.documents.show', $document->id))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->assertStringContainsString('Business Permit.png', (string) $response->headers->get('content-disposition'));
        $this->assertSame('stored-vendor-upload-bytes', $response->streamedContent());
    }

    public function test_officer_document_link_returns_not_found_when_the_file_is_missing(): void
    {
        Storage::fake('local');

        $document = Document::factory()->create(['file_path' => 'vendor1/never-stored.png']);

        $this->actingAs($this->officer)
            ->get(route('admin.documents.show', $document->id))
            ->assertNotFound();
    }

    public function test_officer_can_view_the_archived_reports(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.archived'))
            ->assertOk()
            ->assertSee('Archived Reports')
            ->assertSee('Garcia Textiles');
    }

    public function test_officer_can_view_the_vendor_directory(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.vendors'))
            ->assertOk()
            ->assertSee('Vendor Profiles')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_officer_can_open_a_vendor_profile(): void
    {
        $this->seed(DemoDataSeeder::class);

        $vendor = Vendor::where('company_name', 'Santos Trading Corp.')->firstOrFail();

        $this->actingAs($this->officer)
            ->get(route('admin.vendors.show', $vendor->id))
            ->assertOk()
            ->assertSee('Santos Trading Corp.')
            ->assertSee('Reference biometrics')
            ->assertSee('Submission history');
    }

    public function test_unknown_vendor_returns_not_found(): void
    {
        $this->actingAs($this->officer)->get(route('admin.vendors.show', 999999))->assertNotFound();
    }

    public function test_officer_can_view_the_risk_logs(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.risk-logs'))
            ->assertOk()
            ->assertSee('Risk Logs')
            ->assertSee('Document tampering suspected')
            ->assertSee('Signature verification unavailable')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_officer_can_view_the_notifications(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('High-risk submission detected')
            ->assertSee('Mark all as read');
    }

    public function test_vendor_cannot_access_the_review_pages(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $document = Document::factory()->create();

        $this->actingAs($user)->get(route('admin.pending'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.submissions.show', 1042))->assertForbidden();
        $this->actingAs($user)->get(route('admin.documents.show', $document->id))->assertForbidden();
        $this->actingAs($user)->get(route('admin.archived'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.vendors'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.vendors.show', 1))->assertForbidden();
        $this->actingAs($user)->get(route('admin.risk-logs'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.notifications'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.pending'))->assertRedirect('/login');
        $this->get(route('admin.archived'))->assertRedirect('/login');
        $this->get(route('admin.vendors'))->assertRedirect('/login');
        $this->get(route('admin.risk-logs'))->assertRedirect('/login');
        $this->get(route('admin.notifications'))->assertRedirect('/login');
    }
}
