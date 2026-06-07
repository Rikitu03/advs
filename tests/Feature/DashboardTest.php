<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_unverified_users_are_redirected_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_vendor_is_dispatched_to_the_vendor_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('vendor.dashboard'));
        $this->actingAs($user)->get(route('vendor.dashboard'))->assertOk();
    }

    public function test_compliance_officer_is_dispatched_to_the_admin_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_risk_manager_is_dispatched_to_the_risk_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_RISK_MANAGER)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('risk.dashboard'));
        $this->actingAs($user)->get(route('risk.dashboard'))->assertOk();
    }

    public function test_admin_is_dispatched_to_the_admin_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_vendor_cannot_access_the_admin_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }
}
