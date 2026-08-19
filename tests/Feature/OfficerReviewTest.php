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

    /**
     * A region YOLOv8 actually found, but with no vendor reference enrolled yet
     * (or no issuer reference logo yet), must not be reported to the officer as
     * "no region detected" — that blames the detector for a missing-enrollment
     * condition it has nothing to do with.
     */
    public function test_drill_down_distinguishes_a_detected_but_unreferenced_region_from_a_true_detection_miss(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'signature_detected' => false,
            'signature_bbox' => [10, 20, 30, 40],
            'signature_passed' => null,
            'signature_score' => null,
            'stamp_detected' => false,
            'stamp_bbox' => [50, 60, 70, 80],
            'stamp_passed' => null,
            'stamp_score' => null,
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertTrue($components['signature']['detected']);
        $this->assertFalse($components['signature']['verified']);
        $this->assertSame(
            'Signature region detected, but no reference is enrolled for this vendor yet.',
            $components['signature']['detail'],
        );
        $this->assertSame(
            route('admin.documents.show', $document->id),
            $components['signature']['crop']['url'],
        );
        $this->assertSame([10, 20, 30, 40], $components['signature']['crop']['box']);

        $this->assertTrue($components['stamp']['detected']);
        $this->assertFalse($components['stamp']['verified']);
        $this->assertSame(
            'Stamp/logo region detected, but no reference logo is on file for this issuer yet.',
            $components['stamp']['detail'],
        );
        $this->assertSame([50, 60, 70, 80], $components['stamp']['crop']['box']);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertDontSee('No signature region was detected by YOLOv8')
            ->assertDontSee('No stamp region was detected by YOLOv8')
            ->assertSee('no reference is enrolled for this vendor yet')
            ->assertSee('no reference logo is on file for this issuer yet')
            ->assertSee(route('admin.documents.show', $document->id));
    }

    /**
     * A region detected on a PDF upload has no accurate crop preview (its box
     * is relative to the 300-DPI rendered page, not the PDF bytes an <img>
     * would load), so the drill-down must fall back to text only — no broken
     * image reference.
     */
    public function test_drill_down_omits_the_crop_preview_for_a_pdf_upload(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        $document = Document::factory()->for($vendor)->for($submission)->create([
            'mime_type' => 'application/pdf',
        ]);
        ValidationResult::factory()->for($document)->create([
            'signature_detected' => false,
            'signature_bbox' => [10, 20, 30, 40],
            'signature_passed' => null,
            'signature_score' => null,
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertTrue($components['signature']['detected']);
        $this->assertNull($components['signature']['crop']);
    }

    /**
     * A genuine detection miss (no bbox at all) must keep its original, accurate
     * "no region detected" wording — only the misattributed case changes.
     */
    public function test_drill_down_keeps_the_original_message_for_a_true_detection_miss(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);

        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'signature_detected' => false,
            'signature_bbox' => null,
            'signature_passed' => null,
            'signature_score' => null,
            'stamp_detected' => false,
            'stamp_bbox' => null,
            'stamp_passed' => null,
            'stamp_score' => null,
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertFalse($components['signature']['detected']);
        $this->assertSame('No signature region detected.', $components['signature']['detail']);

        $this->assertFalse($components['stamp']['detected']);
        $this->assertSame('No stamp/logo region detected.', $components['stamp']['detail']);
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

    public function test_drill_down_builds_ocr_field_rows_from_the_persisted_field_map(): void
    {
        $detail = $this->detailForOcrFields([
            'tin' => ['name' => 'TIN', 'value' => '009-028-463-000', 'required' => true,
                'matched' => true, 'confidence' => 96.0, 'warnings' => []],
            'revenue_district_officer' => ['name' => 'Revenue District Officer', 'value' => null,
                'required' => false, 'matched' => false, 'confidence' => null, 'warnings' => []],
        ], $document);

        $rows = $detail['ocr_fields_by_document'][(string) $document->id];

        // Template order is preserved, and an unmatched optional field still gets
        // a row — "not found" is information the officer needs.
        $this->assertSame(['tin', 'revenue_district_officer'], array_column($rows, 'key'));
        $this->assertSame('TIN', $rows[0]['label']);
        $this->assertSame('009-028-463-000', $rows[0]['value']);
        $this->assertTrue($rows[0]['required']);
        $this->assertNull($rows[0]['warning']);
        $this->assertNull($rows[1]['value']);
        $this->assertNull($rows[1]['warning']);
    }

    public function test_drill_down_marks_suspect_ocr_values_with_a_warning(): void
    {
        $detail = $this->detailForOcrFields([
            'rdo_code' => ['name' => 'Revenue District No. (RDO)', 'value' => 'REVENUE DISTRICT',
                'required' => false, 'matched' => true, 'confidence' => 41.0,
                'warnings' => ['format_mismatch', 'low_confidence']],
            'trade_name' => ['name' => 'Trade Name', 'value' => 'EE Bincn', 'required' => true,
                'matched' => true, 'confidence' => 88.0, 'warnings' => ['noisy_text']],
            'line_of_business' => ['name' => 'Line of Business / PSIC', 'value' => null,
                'required' => true, 'matched' => false, 'confidence' => null,
                'warnings' => ['not_found']],
        ], $document);

        $rows = collect($detail['ocr_fields_by_document'][(string) $document->id])->keyBy('key');

        // The chip shows the highest-priority reason; the tooltip lists them all,
        // and the low-confidence reason carries the measured percentage.
        $this->assertSame('Format', $rows['rdo_code']['warning']['label']);
        $this->assertCount(2, $rows['rdo_code']['warning']['reasons']);
        $this->assertStringContainsString('(41%)', $rows['rdo_code']['warning']['reasons'][1]);

        $this->assertSame('Noisy', $rows['trade_name']['warning']['label']);
        $this->assertSame('Not found', $rows['line_of_business']['warning']['label']);
    }

    public function test_drill_down_has_no_ocr_field_rows_when_the_field_map_is_absent(): void
    {
        // Results written before the ocr_fields column existed keep only their
        // raw text; the panel falls back to it rather than rendering nothing.
        $detail = $this->detailForOcrFields(null, $document);

        $this->assertSame([], $detail['ocr_fields_by_document'][(string) $document->id]);
        $this->assertSame('BUREAU OF INTERNAL REVENUE', $detail['ocr_by_document'][(string) $document->id]);
    }

    /**
     * A one-document pending submission whose validation result carries the given
     * field map, presented through the drill-down.
     *
     * @param  array<string, mixed>|null  $fields
     * @return array<string, mixed>
     */
    private function detailForOcrFields(?array $fields, ?Document &$document = null): array
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();

        ValidationResult::factory()->for($document)->create([
            'ocr_extracted_text' => 'BUREAU OF INTERNAL REVENUE',
            'ocr_fields' => $fields,
        ]);

        return SubmissionPresenter::detail($submission->fresh());
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
