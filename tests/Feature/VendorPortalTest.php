<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)
            ->get(route('vendor.submit'))
            ->assertOk()
            ->assertSee('Submit Documents')
            ->assertSee('Document submission')
            ->assertSee('Drop files here or click to upload')
            ->assertSee('File queue')
            ->assertSee('Business Permit')
            ->assertSee('BIR Permit')
            ->assertSee('Financial Statement')
            ->assertSee('Upload ready files')
            ->assertSee('Retry')
            ->assertSee('Suggested')
            ->assertSee('PDF, PNG, JPG');
    }

    public function test_vendor_can_view_their_submissions(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

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
