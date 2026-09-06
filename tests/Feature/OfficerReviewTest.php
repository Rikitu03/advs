<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PipelinePageResult;
use App\Models\PipelineRun;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
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
            ->assertSee('Officer decision')
            ->assertDontSee('Raw OCR text')
            ->assertDontSee('<pre');
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
            'flags' => ['missing_required_fields:form_no,tin,rdo_code', 'no_signature_detected', 'stamp_tampered'],
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
            ['Missing required fields: Form No, TIN, RDO Code', 'No signature detected', 'Stamp has scan/copy texture'],
            $detail['flags_by_document'][0]['flags'],
        );

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('Missing required fields: Form No, TIN, RDO Code')
            ->assertSee('No signature detected')
            ->assertSee('Stamp has scan/copy texture')
            ->assertDontSee('Stamp tampered')
            ->assertDontSee('stamp_tampered');
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
        $this->assertSame('Stamp texture check unavailable', SubmissionPresenter::flagLabel('stamp_tamper_unavailable'));
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

    public function test_drill_down_renders_each_signature_comparison_row(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'mime_type' => 'image/png',
        ]);

        ValidationResult::factory()->for($document)->create([
            'signature_detected' => true,
            'signature_bbox' => [50, 60, 70, 80],
            'signature_score' => 0.4374,
            'signature_distance' => 1.4,
            'signature_passed' => false,
            'signature_comparisons' => [
                [
                    'page_index' => 1,
                    'box' => [10, 20, 30, 40],
                    'confidence' => 0.91,
                    'match' => true,
                    'distance' => 0.7,
                    'similarity' => 0.7186,
                    'threshold' => 1.243976,
                    'score' => 0.7186,
                ],
                [
                    'page_index' => 1,
                    'box' => [50, 60, 70, 80],
                    'confidence' => 0.84,
                    'match' => false,
                    'distance' => 1.4,
                    'similarity' => 0.416,
                    'threshold' => 1.243976,
                    'score' => 0.4374,
                ],
            ],
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertCount(2, $components['signature']['comparisons']);
        $this->assertSame([10, 20, 30, 40], $components['signature']['comparisons'][0]['crop']['box']);
        $this->assertSame([50, 60, 70, 80], $components['signature']['comparisons'][1]['crop']['box']);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('Signature 1')
            ->assertSee('Signature 2')
            ->assertSee('91% detected')
            ->assertSee('84% detected');
    }

    public function test_business_permit_issuer_evidence_panel_remains_removed(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $permitTypeId = (int) DB::table('document_types')->where('code', 'business_permit')->value('id');
        $vendor = Vendor::factory()->create([
            'company_name' => 'Acme Foods Inc',
            'business_city' => 'Makati',
            'business_permit_number' => 'BP-2026-99',
        ]);
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create(['document_type_id' => $permitTypeId]);

        ValidationResult::factory()->for($document)->create([
            'classification_label' => 'business_permit',
            'classification_confidence' => 0.997,
            'detected_city' => 'Makati',
            'ocr_fields' => [
                '__business_permit' => [
                    'issuer_city_raw' => 'LUNGSOD NG MAKATI',
                    'issuer_city_canonical' => 'Makati',
                    'layout_key' => 'makati',
                    'layout_version' => '2026-08-24',
                    'fields' => [
                        'permit_no' => [
                            'value' => 'BP/2026/99',
                            'normalized_value' => 'BP/2026/99',
                            'source_page' => 1,
                            'bbox' => [1, 2, 3, 4],
                            'confidence' => 94,
                            'source' => 'tesseract',
                        ],
                    ],
                    'identity' => [
                        'score' => 1.0,
                        'validity' => 'valid',
                        'checks' => [
                            'permit_number' => [
                                'field' => 'permit_no',
                                'available' => true,
                                'matched' => true,
                                'expected' => 'BP-2026-99',
                            ],
                        ],
                        'flags' => [],
                    ],
                ],
            ],
            'flags' => ['unreferenced_logo'],
        ]);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('OCR extracted fields')
            ->assertDontSee('Business permit issuer evidence')
            ->assertDontSee('Makati layout')
            ->assertDontSee('bbox 1, 2, 3, 4');
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

    public function test_drill_down_prefers_curated_references_and_keeps_private_reference_routes_protected(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => $documentTypeId,
            'mime_type' => 'image/png',
            'file_path' => 'documents/submission.png',
        ]);
        Storage::put('signatures/'.$vendor->id.'/reference.png', 'enrolled-signature');
        Storage::put('documents/submission.png', 'uploaded-document');
        DB::table('vendor_embeddings')->insert([
            'vendor_id' => $vendor->id,
            'signature_embedding' => json_encode([0.1, 0.2]),
            'signature_image_path' => 'signatures/'.$vendor->id.'/reference.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $logoReferenceId = DB::table('logo_references')->insertGetId([
            'document_type_id' => $documentTypeId,
            'city' => '',
            'feature_vector' => json_encode([0.1, 0.2]),
            'reference_image_path' => 'logo_references/bir/national.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::put('logo_references/bir/national.png', 'issuer-logo');
        ValidationResult::factory()->for($document)->create([
            'submission_id' => $submission->id,
            'logo_reference_id' => $logoReferenceId,
            'stamp_bbox' => [50, 60, 70, 80],
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertSame(
            route('admin.signature.show', $vendor->id),
            $components['signature']['reference_image_url'],
        );
        $this->assertSame(
            route('admin.issuer-logo-references.show', 'bir-permit-logo'),
            $components['stamp']['reference_image_url'],
        );
        $this->assertSame(['bir-permit-logo', 'bir-seal'], array_column($components['stamp']['references'], 'key'));
        $this->assertSame(['curated', 'curated'], array_column($components['stamp']['references'], 'source'));

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Query (this submission)',
                'Detected signature',
                'Reference (enrolled)',
                'Enrolled signature reference',
            ])
            ->assertSeeHtml('data-issuer-reference-card="bir-permit-logo"')
            ->assertSeeHtml('data-issuer-reference-card="bir-seal"')
            ->assertSeeInOrder(['BIR Permit Logo', 'BIR Seal'])
            ->assertSee('BIR Permit Logo')
            ->assertSee('BIR Seal');

        $this->actingAs($this->officer)
            ->get(route('admin.signature.show', $vendor->id))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($this->officer)
            ->get(route('admin.logo-references.show', $logoReferenceId))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_drill_down_falls_back_to_a_database_reference_when_the_catalog_has_none(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'sec_registration')->value('id');
        $document = Document::factory()->for($vendor)->for($submission)->create(['document_type_id' => $documentTypeId]);
        $logoReferenceId = DB::table('logo_references')->insertGetId([
            'document_type_id' => $documentTypeId,
            'city' => '',
            'label' => 'SEC enrolled seal',
            'feature_vector' => json_encode([0.1, 0.2]),
            'reference_image_path' => 'logo_references/sec/national.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::put('logo_references/sec/national.png', 'issuer-logo');
        ValidationResult::factory()->for($document)->create([
            'submission_id' => $submission->id,
            'logo_reference_id' => $logoReferenceId,
            'stamp_bbox' => [10, 20, 30, 40],
        ]);

        $references = SubmissionPresenter::detail($submission->fresh())['component_sets']['all']['stamp']['references'];

        $this->assertCount(1, $references);
        $this->assertSame('enrolled-reference', $references[0]['key']);
        $this->assertSame('enrolled', $references[0]['source']);
        $this->assertSame('SEC enrolled seal', $references[0]['label']);
        $this->assertSame(route('admin.logo-references.show', $logoReferenceId), $references[0]['url']);
    }

    public function test_curated_reference_route_streams_only_manifest_listed_files_to_officers(): void
    {
        $this->get(route('admin.issuer-logo-references.show', 'bir-seal'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->officer)
            ->get(route('admin.issuer-logo-references.show', 'bir-seal'))
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($this->officer)
            ->get(route('admin.issuer-logo-references.show', 'unknown-reference'))
            ->assertNotFound();
    }

    public function test_detected_logo_without_a_usable_reference_keeps_both_panels(): void
    {
        $documentTypeId = DB::table('document_types')->insertGetId([
            'name' => 'Unsupported National Certificate',
            'code' => 'unsupported_national',
            'issuer_scope' => 'national',
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => $documentTypeId,
            'mime_type' => 'image/png',
        ]);
        ValidationResult::factory()->for($document)->create([
            'submission_id' => $submission->id,
            'stamp_detected' => false,
            'stamp_bbox' => [10, 20, 30, 40],
            'stamp_passed' => null,
        ]);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Query (this submission)',
                'Issuer references',
                'No references currently available',
            ]);
    }

    public function test_presenter_merges_per_candidate_scores_in_manifest_order(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $document = Document::factory()->for($vendor)->for($submission)->create(['document_type_id' => $documentTypeId]);
        ValidationResult::factory()->for($document)->create(['submission_id' => $submission->id]);
        $run = PipelineRun::factory()->create([
            'document_id' => $document->id,
            'submission_id' => $submission->id,
        ]);
        PipelinePageResult::factory()->create([
            'pipeline_run_id' => $run->id,
            'document_id' => $document->id,
            'submission_id' => $submission->id,
            'stages' => [
                'stamp' => [
                    'best_reference_key' => 'bir-seal',
                    'reference_matches' => [
                        ['key' => 'bir-seal', 'similarity_score' => 0.94, 'match' => true],
                        ['key' => 'bir-permit-logo', 'similarity_score' => 0.71, 'match' => false],
                    ],
                ],
            ],
        ]);

        $references = SubmissionPresenter::detail($submission->fresh())['component_sets']['all']['stamp']['references'];

        $this->assertSame(['bir-permit-logo', 'bir-seal'], array_column($references, 'key'));
        $this->assertSame([71, 94], array_column($references, 'similarity'));
        $this->assertFalse($references[0]['best']);
        $this->assertTrue($references[1]['best']);
    }

    public function test_reference_image_routes_return_not_found_for_stale_storage_paths(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        DB::table('vendor_embeddings')->insert([
            'vendor_id' => $vendor->id,
            'signature_image_path' => 'signatures/missing.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $logoReferenceId = DB::table('logo_references')->insertGetId([
            'document_type_id' => $documentTypeId,
            'city' => '',
            'feature_vector' => json_encode([0.1, 0.2]),
            'reference_image_path' => 'logo_references/bir/missing.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->officer)
            ->get(route('admin.signature.show', $vendor->id))
            ->assertNotFound();
        $this->actingAs($this->officer)
            ->get(route('admin.logo-references.show', $logoReferenceId))
            ->assertNotFound();
    }

    public function test_drill_down_ignores_malformed_detection_boxes(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create(['mime_type' => 'image/png']);
        ValidationResult::factory()->for($document)->create([
            'signature_bbox' => [10, 20, 5, 40],
            'stamp_bbox' => ['bad', 0, 20, 20],
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertNull($components['signature']['crop']);
        $this->assertNull($components['stamp']['crop']);
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

    public function test_drill_down_describes_stamp_texture_as_scan_copy_without_claiming_tampering(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'stamp_tampered' => true,
            'stamp_bbox' => [10, 10, 80, 80],
        ]);

        $components = SubmissionPresenter::detail($submission->fresh())['component_sets']['all'];

        $this->assertTrue($components['stamp']['texture_checked']);
        $this->assertTrue($components['stamp']['scan_copy_texture']);

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee('Issuer References Matching')
            ->assertSee('Scan/copy texture detected')
            ->assertSee('does not mean the stamp artwork was altered')
            ->assertDontSee('Stamp tampered')
            ->assertDontSee('stamp_tampered');
    }

    public function test_drill_down_provides_per_document_ocr_filters_without_raw_text(): void
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
        $this->assertArrayNotHasKey('ocr_by_document', $detail);
    }

    public function test_drill_down_does_not_expose_raw_ocr_when_ocr_fields_are_unavailable(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create(['ocr_extracted_text' => null]);

        $detail = SubmissionPresenter::detail($submission->fresh());

        $this->assertArrayNotHasKey('ocr_by_document', $detail);
        $this->assertSame([], $detail['ocr_fields_by_document'][(string) $document->id]);
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

    public function test_bir_ocr_profile_renders_only_the_seven_review_fields_in_order(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => $documentTypeId,
            'original_filename' => 'misleading-dti-name.pdf',
        ]);
        ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'tin' => ['name' => 'TIN', 'value' => '009-028-463-000'],
                'registered_name' => ['name' => 'Registered Name', 'value' => 'ACME FOODS'],
                'trade_name' => ['name' => 'Trade Name', 'value' => 'ACME'],
                'line_of_business' => ['name' => 'Line of Business / PSIC', 'value' => 'Food retail'],
                'registration_date' => ['name' => 'Registration Date', 'value' => '01/02/2020'],
                'date_issued' => ['name' => 'Date Issued', 'value' => 'JAN 02 2020'],
                'rdo_code' => ['name' => 'Revenue District No. (RDO)', 'value' => '47'],
                'tax_types' => ['name' => 'Registered Activity(ies)', 'value' => 'VAT'],
            ],
        ]);

        $rows = SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id];

        $this->assertSame([
            'tin',
            'registered_name',
            'registered_address',
            'trade_name',
            'line_of_business',
            'registration_date',
            'date_issued',
        ], array_column($rows, 'key'));
        $this->assertSame([
            'TIN',
            'Registered Name',
            'Registered Address',
            'Trade Name',
            'Line of Business / PSIC',
            'Registration Date',
            'Date Issued',
        ], array_column($rows, 'label'));
        $this->assertNull($rows[2]['value']);
        $this->assertNull($rows[2]['comparison']);
    }

    public function test_dti_ocr_profile_renders_only_the_six_review_fields_in_order(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $documentTypeId = (int) DB::table('document_types')->where('code', 'dti_registration')->value('id');
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => $documentTypeId,
            'original_filename' => 'looks-like-bir.pdf',
        ]);
        ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'business_name' => ['name' => 'Business Name', 'value' => 'ACME MERCANTILE'],
                'business_address' => ['name' => 'Business Address', 'value' => '123 RIZAL STREET'],
                'owner_representative_name' => ['name' => 'Owner / Representative Name', 'value' => 'JUAN DELA CRUZ'],
                'date_issued' => ['name' => 'Valid Date', 'value' => '01/02/2026'],
                'expiry_date' => ['name' => 'Expiration Date', 'value' => '01/02/2031'],
                'certificate_no' => ['name' => 'Certificate Number', 'value' => 'BN 1234567'],
                'trn_no' => ['name' => 'TRN', 'value' => 'DTI-2026-12345678'],
            ],
        ]);

        $rows = SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id];

        $this->assertSame([
            'owner_representative_name',
            'business_name',
            'business_address',
            'date_issued',
            'expiry_date',
            'trn_no',
        ], array_column($rows, 'key'));
        $this->assertSame([
            'Owner / Representative Name',
            'Business Name',
            'Business Address',
            'Valid Date',
            'Expiration Date',
            'Transaction Reference Number (TRN)',
        ], array_column($rows, 'label'));
        $this->assertNotContains('certificate_no', array_column($rows, 'key'));

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSeeInOrder([
                'Owner / Representative Name',
                'Business Name',
                'Business Address',
                'Valid Date',
                'Expiration Date',
                'Transaction Reference Number (TRN)',
            ])
            ->assertDontSee('Certificate Number');
    }

    public function test_drill_down_compares_ocr_fields_with_vendor_registration_details(): void
    {
        $vendor = Vendor::factory()->create([
            'company_name' => 'Northern Star Finance Inc.',
            'trade_name' => 'Barporating Solutions',
            'tin' => '388-063-009-0000',
            'dti_registration_number' => null,
            'registration_number' => null,
        ]);
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'registered_name' => ['name' => 'Registered Name', 'value' => 'NORTHERN STAR FINANCE INC.', 'warnings' => []],
                'trade_name' => ['name' => 'Trade Name', 'value' => 'BARPORATING SOLUTIONS', 'warnings' => []],
                'tin' => ['name' => 'TIN', 'value' => 'X388-063-009-0000Y', 'warnings' => []],
                'certificate_no' => ['name' => 'Certificate Number', 'value' => 'BN-99', 'warnings' => []],
            ],
        ]);

        $rows = collect(SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id])
            ->keyBy('key');

        $this->assertTrue($rows['registered_name']['comparison']);
        $this->assertTrue($rows['trade_name']['comparison']);
        $this->assertFalse($rows['tin']['comparison']);
        $this->assertNull($rows['certificate_no']['comparison']);
        $this->assertSame('388-063-009-0000', $rows['tin']['registration_value']);
    }

    public function test_drill_down_matches_dti_owner_names_against_representative_variants(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
        ]);
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => DB::table('document_types')->where('code', 'dti_registration')->value('id'),
        ]);
        $result = ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'owner_representative_name' => ['value' => 'MARIA SANTOS DELA CRUZ JR.'],
            ],
        ]);

        foreach ([
            'MARIA SANTOS DELA CRUZ JR.',
            'Maria-Dela Cruz',
            'Dela Cruz, Maria Santos, Jr.',
        ] as $ocrName) {
            $result->update([
                'ocr_fields' => [
                    'owner_representative_name' => ['value' => $ocrName],
                ],
            ]);

            $rows = collect(SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id])
                ->keyBy('key');

            $this->assertTrue($rows['owner_representative_name']['comparison']);
            $this->assertStringContainsString('Maria Santos Dela Cruz Jr.', $rows['owner_representative_name']['registration_value']);
        }
    }

    public function test_drill_down_marks_owner_fields_unavailable_without_a_representative_and_mismatched_when_one_differs(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create([
            'document_type_id' => DB::table('document_types')->where('code', 'dti_registration')->value('id'),
        ]);
        $result = ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'owner_representative_name' => ['value' => 'Maria Santos'],
            ],
        ]);

        $rows = collect(SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id])
            ->keyBy('key');
        $this->assertNull($rows['owner_representative_name']['comparison']);
        $this->assertNull($rows['owner_representative_name']['registration_value']);

        VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);
        $result->update([
            'ocr_fields' => [
                'owner_representative_name' => ['value' => 'Maria Santos'],
            ],
        ]);

        $rows = collect(SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id])
            ->keyBy('key');
        $this->assertFalse($rows['owner_representative_name']['comparison']);
        $this->assertSame('Ana Reyes / Reyes Ana', $rows['owner_representative_name']['registration_value']);
    }

    public function test_drill_down_resolves_business_and_registration_candidates_when_present(): void
    {
        $vendor = Vendor::factory()->create([
            'company_name' => 'North Star Trading',
            'trade_name' => 'North Star',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026-123456',
            'nature_of_business' => 'Food Retail',
            'business_street' => '123 Main Street',
            'business_barangay' => 'Barangay San Antonio',
            'business_city' => 'Pasig City',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1605',
        ]);
        $submission = Submission::factory()->for($vendor)->create(['status' => Submission::STATUS_PENDING_REVIEW]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create([
            'ocr_fields' => [
                'business_name' => ['value' => 'NORTH STAR TRADING'],
                'name_of_proprietor' => ['value' => 'NORTH STAR TRADING'],
                'business_owner' => ['value' => 'NORTH STAR TRADING'],
                'tin' => ['value' => '123 456 789 000'],
                'trn_no' => ['value' => 'DTI/2026.123456'],
                'business_location' => ['value' => '123 MAIN ST., BRGY. SAN ANTONIO, PASIG CITY, METRO MANILA 1605'],
                'city_issued' => ['value' => 'PASIG CITY'],
                'kind_of_business' => ['value' => 'Food-Retail'],
                'line_of_business' => ['value' => 'FOOD RETAIL'],
            ],
        ]);

        $rows = collect(SubmissionPresenter::detail($submission->fresh())['ocr_fields_by_document'][(string) $document->id])
            ->keyBy('key');

        foreach (['business_name', 'name_of_proprietor', 'business_owner', 'tin', 'trn_no', 'business_location', 'city_issued', 'kind_of_business', 'line_of_business'] as $key) {
            $this->assertTrue($rows[$key]['comparison'], $key.' should resolve a matching vendor candidate.');
        }
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
        // raw text in storage, but the presenter must not expose it.
        $detail = $this->detailForOcrFields(null, $document);

        $this->assertSame([], $detail['ocr_fields_by_document'][(string) $document->id]);
        $this->assertArrayNotHasKey('ocr_by_document', $detail);
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

    public function test_risk_logs_present_stamp_scan_copy_texture_separately_from_tampering(): void
    {
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
            'risk_level' => 'low',
        ]);
        $document = Document::factory()->for($vendor)->for($submission)->create();
        ValidationResult::factory()->for($document)->create(['flags' => ['stamp_tampered']]);

        $this->actingAs($this->officer)
            ->get(route('admin.risk-logs'))
            ->assertOk()
            ->assertSee('Stamp has scan/copy texture')
            ->assertSee('Stamp scan/copy texture')
            ->assertDontSee('stamp_tampered')
            ->assertDontSee('Stamp tampered');
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
        $this->actingAs($user)->get(route('admin.signature.show', 1))->assertForbidden();
        $this->actingAs($user)->get(route('admin.logo-references.show', 1))->assertForbidden();
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
        $this->get(route('admin.signature.show', 1))->assertRedirect('/login');
        $this->get(route('admin.logo-references.show', 1))->assertRedirect('/login');
    }
}
