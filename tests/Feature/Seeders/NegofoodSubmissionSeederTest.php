<?php

namespace Tests\Feature\Seeders;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NegofoodSubmissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    private function negofoodVendor(): Vendor
    {
        return User::query()->where('email', 'vendor@advs.test')->firstOrFail()->vendor;
    }

    public function test_default_seed_gives_negofood_vendor_a_full_submission_history(): void
    {
        $this->seedAll();

        $vendor = $this->negofoodVendor();

        $this->assertSame('Negofood Demo Trading', $vendor->company_name);
        $this->assertSame(Vendor::STATUS_UNDER_REVIEW, $vendor->status);
        $this->assertSame(3, $vendor->submissions()->count());

        $pending = $vendor->submissions()->where('status', Submission::STATUS_PENDING_REVIEW)->first();
        $approved = $vendor->submissions()->where('status', Submission::STATUS_APPROVED)->first();
        $resubmission = $vendor->submissions()->where('status', Submission::STATUS_RESUBMISSION_REQUESTED)->first();

        $this->assertNotNull($pending);
        $this->assertNotNull($approved);
        $this->assertNotNull($resubmission);

        // The showcase submission: one batch bundling all three document types,
        // shaped like a real standby-pipeline run (medium composite risk).
        $this->assertSame(3, $pending->documents()->count());
        $this->assertEqualsWithDelta(60.0, (float) $pending->composite_risk_score, 0.01);
        $this->assertSame('medium', $pending->risk_level);
        $this->assertSame(
            3,
            $pending->documents()->pluck('document_type_id')->unique()->filter()->count(),
            'Each pending document should carry a distinct document type.',
        );

        $pending->documents->each(function (Document $document): void {
            $this->assertSame(Document::STATUS_COMPLETED, $document->processing_status);
            $this->assertNotNull($document->validationResult);
            $this->assertNotEmpty($document->validationResult->flags);
        });

        // Decided history carries a reviewer and, for the resubmission request, comments.
        $this->assertNotNull($approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertNotNull($resubmission->reviewed_by);
        $this->assertNotNull($resubmission->review_comments);
        $this->assertSame('high', $resubmission->risk_level);
    }

    public function test_seeded_documents_are_backed_by_real_stored_files(): void
    {
        $this->seedAll();

        $vendor = $this->negofoodVendor();
        $documents = Document::query()->where('vendor_id', $vendor->id)->get();

        $this->assertSame(5, $documents->count());

        $documents->each(function (Document $document): void {
            Storage::disk('local')->assertExists($document->file_path);
            $this->assertContains($document->mime_type, ['application/pdf', 'image/png']);
            $this->assertSame(
                Storage::disk('local')->size($document->file_path),
                $document->file_size_bytes,
            );
        });
    }

    public function test_seed_creates_vendor_and_officer_notifications(): void
    {
        $this->seedAll();

        $vendorUser = User::query()->where('email', 'vendor@advs.test')->firstOrFail();
        $officer = User::query()->where('role', User::ROLE_COMPLIANCE_OFFICER)->orderBy('id')->firstOrFail();
        $resubmission = $this->negofoodVendor()->submissions()
            ->where('status', Submission::STATUS_RESUBMISSION_REQUESTED)->firstOrFail();

        $vendorTypes = Notification::query()->where('user_id', $vendorUser->id)->pluck('type');
        $this->assertContains(Notification::TYPE_SUBMISSION_RECEIVED, $vendorTypes);
        $this->assertContains(Notification::TYPE_PROCESSING_COMPLETE, $vendorTypes);
        $this->assertContains(Notification::TYPE_DECISION_MADE, $vendorTypes);

        $this->assertTrue(
            Notification::query()
                ->where('user_id', $officer->id)
                ->where('type', Notification::TYPE_HIGH_RISK_ALERT)
                ->where('related_submission_id', $resubmission->id)
                ->exists(),
        );

        // Officers also carry a record of the decisions themselves.
        $this->assertSame(
            2,
            Notification::query()
                ->where('user_id', $officer->id)
                ->where('type', Notification::TYPE_DECISION_MADE)
                ->count(),
        );
    }

    public function test_seeding_twice_does_not_duplicate_demo_data(): void
    {
        $this->seedAll();
        $this->seed(DatabaseSeeder::class);

        $vendor = $this->negofoodVendor();

        $this->assertSame(3, $vendor->submissions()->count());
        $this->assertSame(5, Document::query()->where('vendor_id', $vendor->id)->count());
    }
}
