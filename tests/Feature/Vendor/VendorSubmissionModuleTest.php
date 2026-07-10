<?php

namespace Tests\Feature\Vendor;

use App\Models\Document;
use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Financial Statement')
            ->assertSee('Submit')
            ->assertSee('Remove file')
            ->assertDontSee('Submit another batch')
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
            ->assertSee('audited_financial_statement_2025.pdf');

        $this->assertSame(0, $vendor->submissions()->count());
    }

    public function test_submit_batch_persists_a_real_submission_and_documents_for_the_vendor(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();

        $component = Livewire::actingAs($user)
            ->test('vendor.submit')
            ->call('submitBatch', [[
                'name' => 'bir_certificate.pdf',
                'type' => 'BIR Permit',
                'extension' => 'pdf',
                'sizeBytes' => 2_048_000,
            ], [
                'name' => 'financial_statement.pdf',
                'type' => 'Financial Statement',
                'extension' => 'pdf',
                'sizeBytes' => 1_024_000,
            ]]);

        $component->assertRedirect(route('vendor.submissions'));

        $birCertificateId = DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $financialStatementId = DB::table('document_types')->where('code', 'financial_statement')->value('id');

        $this->assertDatabaseHas('submissions', [
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_PROCESSING,
        ]);
        $this->assertDatabaseHas('documents', [
            'vendor_id' => $vendor->id,
            'original_filename' => 'bir_certificate.pdf',
            'document_type_id' => $birCertificateId,
        ]);
        $this->assertDatabaseHas('documents', [
            'vendor_id' => $vendor->id,
            'original_filename' => 'financial_statement.pdf',
            'document_type_id' => $financialStatementId,
        ]);
    }

    public function test_my_submissions_uses_the_logged_in_vendors_database_records(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $otherVendor = Vendor::factory()->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');
        $financialStatementId = DB::table('document_types')->where('code', 'financial_statement')->value('id');

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
        ]);

        Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'pasig-business-permit.pdf',
            'file_path' => "vendor{$vendor->id}/business_permit00001.pdf",
            'mime_type' => 'application/pdf',
        ]);

        Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $financialStatementId,
            'original_filename' => 'audited-financial-statement.pdf',
            'file_path' => "vendor{$vendor->id}/financial_statement00001.pdf",
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
            ->assertSee('pasig-business-permit.pdf')
            ->assertSee('audited-financial-statement.pdf')
            ->assertSee('Business Permit')
            ->assertSee('Financial Statement')
            ->assertSee('Pending Review')
            ->assertSee('70%')
            ->assertSee('Showing 2 of 2 documents.')
            ->assertDontSee('Unassigned document')
            ->assertDontSee('other-vendor-permit.pdf');
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
