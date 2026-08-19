<?php

namespace Tests\Feature\Vendor;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VendorSubmissionModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_documents_page_keeps_the_queue_layout_without_individual_upload_buttons(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        Vendor::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('vendor.submit'))
            ->assertOk()
            ->assertSee('Queue Files')
            ->assertSee('Assign Document Type')
            ->assertSee('Upload Files')
            ->assertSee('Pending Review')
            ->assertSee('File queue')
            ->assertSee('Drop files here or click to upload')
            ->assertSee('BIR Permit')
            ->assertSee('Business Permit')
            ->assertSee('DTI Registration')
            ->assertSee('Submit')
            ->assertSee('Remove file')
            ->assertSee('File type not allowed')
            ->assertSee('Only PDF, PNG, JPG, or JPEG files up to 10 MB can be added to the file queue.')
            ->assertSee('Preparing')
            ->assertSee('Done')
            ->assertSee('invalidModalOpen')
            ->assertSee('submitWhenProgressCompletes')
            ->assertDontSee('Submit another batch')
            ->assertDontSee('Check File')
            ->assertDontSee('Suggested')
            ->assertDontSee('suggestDocumentType')
            ->assertDontSee('Upload ready files')
            ->assertDontSee('Retry');
    }

    public function test_my_submissions_shows_display_only_fallback_rows_when_vendor_has_no_submissions(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        $this->assertSame(0, $vendor->submissions()->count());

        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee('business_permit_2026.pdf')
            ->assertSee('dti_business_name_registration_2026.png');

        $this->assertSame(0, $vendor->submissions()->count());
    }

    public function test_fallback_rows_group_multiple_documents_under_one_submission(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        $this->assertSame(0, $vendor->submissions()->count());

        // The showcase demo submission bundles three documents (Business
        // Permit + BIR Permit + DTI Registration) under a single batch,
        // mirroring the real Submission → hasMany(Document) model.
        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee('3 files')
            ->assertSee('Included files')
            ->assertSee('business_permit_2026.pdf')
            ->assertSee('bir_certificate_registration.pdf')
            ->assertSee('dti_business_name_registration_2026.png')
            ->assertDontSee('1 files');
    }

    public function test_submit_batch_persists_a_real_submission_and_documents_for_the_vendor(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        $birContent = "%PDF-1.4\n%fake fixture bir\n%%EOF";
        $dtiContent = "%PDF-1.4\n%fake fixture dti\n%%EOF";

        $component = Livewire::actingAs($user)
            ->test('vendor.submit')
            ->set('uploadedFiles', [
                UploadedFile::fake()->createWithContent('bir_certificate.pdf', $birContent),
                UploadedFile::fake()->createWithContent('dti_registration.pdf', $dtiContent),
            ])
            ->call('submitBatch', [[
                'name' => 'bir_certificate.pdf',
                'type' => 'BIR Permit',
                'extension' => 'pdf',
                'sizeBytes' => 2_048_000,
            ], [
                'name' => 'dti_registration.pdf',
                'type' => 'DTI Registration',
                'extension' => 'pdf',
                'sizeBytes' => 1_024_000,
            ]]);

        $component->assertRedirect(route('vendor.submissions'));

        $birCertificateId = DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $dtiRegistrationId = DB::table('document_types')->where('code', 'dti_registration')->value('id');

        $this->assertDatabaseHas('submissions', [
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_PROCESSING,
        ]);
        $this->assertSame(1, $vendor->submissions()->count());
        $this->assertDatabaseHas('documents', [
            'vendor_id' => $vendor->id,
            'original_filename' => 'bir_certificate.pdf',
            'document_type_id' => $birCertificateId,
            // The recorded size must match the stored copy's real byte length.
            // storeAs() moves the livewire-tmp file off disk before the size is
            // read, so the size must come from the persisted file, not the temp
            // upload (which would throw UnableToRetrieveMetadata in the browser).
            'file_size_bytes' => strlen($birContent),
        ]);
        $this->assertDatabaseHas('documents', [
            'vendor_id' => $vendor->id,
            'original_filename' => 'dti_registration.pdf',
            'document_type_id' => $dtiRegistrationId,
            'file_size_bytes' => strlen($dtiContent),
        ]);

        $vendor->documents()->get()->each(function (Document $document): void {
            Storage::assertExists($document->file_path);
            $this->assertSame(
                Storage::disk('local')->size($document->file_path),
                $document->file_size_bytes,
            );
        });

        // Stage 0 dispatches the validation pipeline once per document and
        // notifies the vendor that the submission was received (§7).
        Queue::assertPushed(ProcessDocumentJob::class, 2);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => Notification::TYPE_SUBMISSION_RECEIVED,
        ]);
    }

    public function test_submit_batch_rejects_a_manifest_with_no_matching_uploads(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test('vendor.submit')
            ->call('submitBatch', [[
                'name' => 'ghost-file.pdf',
                'type' => 'BIR Permit',
                'extension' => 'pdf',
                'sizeBytes' => 1_024,
            ]])
            ->assertHasErrors('uploadedFiles');

        $this->assertSame(0, $vendor->submissions()->count());
        $this->assertSame(0, Document::count());
        Queue::assertNothingPushed();
    }

    public function test_submit_batch_skips_files_whose_real_mime_type_is_not_allowed(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test('vendor.submit')
            ->set('uploadedFiles', [
                UploadedFile::fake()->createWithContent('disguised.pdf', '<?php echo "not a pdf"; ?>'),
            ])
            ->call('submitBatch', [[
                'name' => 'disguised.pdf',
                'type' => 'BIR Permit',
                'extension' => 'pdf',
                'sizeBytes' => 1_024,
            ]])
            ->assertHasErrors();

        $this->assertSame(0, $vendor->submissions()->count());
        Queue::assertNothingPushed();
    }

    public function test_my_submissions_uses_the_logged_in_vendors_database_records(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $otherVendor = Vendor::factory()->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        $dtiRegistrationId = DB::table('document_types')->where('code', 'dti_registration')->value('id');

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
        ]);

        $businessPermit = Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'pasig-business-permit.pdf',
            'file_path' => "vendor{$vendor->id}/business_permit00001.pdf",
            'mime_type' => 'application/pdf',
        ]);

        $dtiRegistration = Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $dtiRegistrationId,
            'original_filename' => 'dti-business-name-registration.pdf',
            'file_path' => "vendor{$vendor->id}/dti_registration00001.pdf",
            'mime_type' => 'application/pdf',
        ]);

        $otherSubmission = Submission::factory()->for($otherVendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
        ]);

        Document::factory()->for($otherSubmission)->for($otherVendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'other-vendor-permit.pdf',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee("Document Submission #{$submission->id}")
            ->assertSee('2 files')
            ->assertSee('pasig-business-permit.pdf')
            ->assertSee('dti-business-name-registration.pdf')
            ->assertSee('Business Permit')
            ->assertSee('DTI Business Name Registration')
            ->assertSee('Pending Review')
            ->assertSee('70%')
            ->assertSee('Included files')
            ->assertSee(route('vendor.documents.show', $businessPermit), false)
            ->assertSee(route('vendor.documents.show', $dtiRegistration), false)
            ->assertSee('target="_blank"', false)
            ->assertSee('Showing 1 of 1 submissions.')
            ->assertDontSee('Unassigned document')
            ->assertDontSee('other-vendor-permit.pdf');
    }

    public function test_vendor_can_open_their_uploaded_document_file(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        $path = "vendor{$vendor->id}/business_permit00001.pdf";

        Storage::put($path, 'document contents');

        $submission = Submission::factory()->for($vendor)->create();
        $document = Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'business-permit.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->actingAs($user)
            ->get(route('vendor.documents.show', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString('business-permit.pdf', $response->headers->get('content-disposition'));
    }

    public function test_vendor_cannot_open_another_vendors_uploaded_document_file(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        Vendor::factory()->for($user)->create();
        $otherVendor = Vendor::factory()->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        $path = "vendor{$otherVendor->id}/business_permit00001.pdf";

        Storage::put($path, 'document contents');

        $submission = Submission::factory()->for($otherVendor)->create();
        $document = Document::factory()->for($submission)->for($otherVendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'other-business-permit.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.documents.show', $document))
            ->assertNotFound();
    }

    public function test_processing_submissions_start_at_the_first_progress_stage(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PROCESSING,
        ]);

        Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'queued-business-permit.pdf',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee('queued-business-permit.pdf')
            ->assertSee('35%')
            ->assertDontSee('25%');
    }

    public function test_final_submission_statuses_show_complete_progress(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_APPROVED,
        ]);

        Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'approved-business-permit.pdf',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee('approved-business-permit.pdf')
            ->assertSee('100%');
    }
}
