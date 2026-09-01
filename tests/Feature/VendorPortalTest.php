<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VendorPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_view_the_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)
            ->get(route('vendor.dashboard'))
            ->assertOk()
            ->assertSee('Vendor workspace')
            ->assertSee('Recent submissions');
    }

    public function test_vendor_can_view_the_submit_documents_page(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        Vendor::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('vendor.submit'))
            ->assertOk()
            ->assertSee('Submit Documents')
            ->assertSee('Document submission')
            ->assertSee('Queue Files')
            ->assertSee('Assign Document Type')
            ->assertSee('Upload Files')
            ->assertSee('Pending Review')
            ->assertSee('Business Permit')
            ->assertSee('BIR Registration')
            ->assertSee('DTI Registration')
            ->assertSee('PDF, PNG, JPG')
            ->assertDontSee('Upload ready files')
            ->assertDontSee('Retry');
    }

    public function test_vendor_can_view_their_submissions(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $user = User::factory()->role(User::ROLE_VENDOR)->create();
        $vendor = Vendor::factory()->for($user)->create();
        $businessPermitId = DB::table('document_types')->where('code', 'business_permit')->value('id');

        $submission = Submission::factory()->for($vendor)->create([
            'status' => Submission::STATUS_PENDING_REVIEW,
        ]);

        Document::factory()->for($submission)->for($vendor)->create([
            'document_type_id' => $businessPermitId,
            'original_filename' => 'business_permit_2026.pdf',
            'file_path' => "vendor{$vendor->id}/business_permit00001.pdf",
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.submissions'))
            ->assertOk()
            ->assertSee('My Submissions')
            ->assertSee('business_permit_2026.pdf')
            ->assertSee('Pending Review');
    }

    public function test_vendor_can_view_notifications(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        Notification::factory()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_SUBMISSION_RECEIVED,
            'subject' => 'Submission received',
            'body' => 'Your submission has been received and is being processed.',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Your submission has been received')
            ->assertSee('Mark all as read');
    }

    public function test_vendor_can_view_profile(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Manage your profile and account settings')
            ->assertSee('Update your name and email address')
            ->assertDontSee('settings/appearance', false);
    }

    public function test_non_vendor_users_cannot_access_vendor_tabs(): void
    {
        $user = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('vendor.submit'))->assertForbidden();
        $this->actingAs($user)->get(route('vendor.submissions'))->assertForbidden();
        $this->actingAs($user)->get(route('vendor.notifications'))->assertForbidden();
        $this->actingAs($user)->get(route('vendor.profile'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('vendor.dashboard'))->assertRedirect('/login');
        $this->get(route('vendor.submit'))->assertRedirect('/login');
        $this->get(route('vendor.submissions'))->assertRedirect('/login');
        $this->get(route('vendor.notifications'))->assertRedirect('/login');
        $this->get(route('vendor.profile'))->assertRedirect('/login');
    }
}
