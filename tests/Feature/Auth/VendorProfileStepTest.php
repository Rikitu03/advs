<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VendorProfileStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_vendor_has_a_completed_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertNotNull($user->vendor_profile_completed_at);
    }

    public function test_without_vendor_profile_state_marks_the_profile_incomplete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->assertFalse($user->hasCompletedVendorProfile());
        $this->assertNull($user->vendor_profile_completed_at);
    }

    public function test_business_step_renders_for_a_vendor_without_a_profile(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $this->actingAs($user)
            ->get(route('business.create'))
            ->assertOk()
            ->assertSee('Business & owner details');
    }

    public function test_vendor_can_submit_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('company_name', 'Negofood Trading')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('tin', '123-456-789-000')
            ->set('dti_registration_number', 'DTI-2026001')
            ->set('business_permit_number', 'BP-2026-555')
            ->set('nature_of_business', 'Food retail')
            ->set('business_street', '12 Ortigas Ave')
            ->set('business_barangay', 'Barangay San Antonio')
            ->set('business_city', 'Pasig')
            ->set('business_province', 'Metro Manila')
            ->set('business_postal_code', '1600')
            ->set('first_name', 'Jose')
            ->set('last_name', 'Rizal')
            ->set('date_of_birth', '1990-06-19')
            ->set('gender', 'male')
            ->set('contact_number', '+63 917 000 0000')
            ->set('government_id_type', 'national_id')
            ->set('government_id_number', '1234-5678-9012')
            ->set('home_address', '37 Real St, Calamba')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('signature.create'));

        $user->refresh();
        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertSame('Negofood Trading', $user->vendor->company_name);
    }

    public function test_business_step_requires_core_fields(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->call('save')
            ->assertHasErrors(['company_name', 'business_entity_type', 'tin', 'first_name', 'last_name']);
    }

    public function test_sole_proprietorship_requires_a_dti_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('sec_registration_number', '')
            ->set('dti_registration_number', '')
            ->call('save')
            ->assertHasErrors(['dti_registration_number']);
    }

    public function test_corporation_requires_a_sec_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'corporation')
            ->set('dti_registration_number', '')
            ->set('sec_registration_number', '')
            ->call('save')
            ->assertHasErrors(['sec_registration_number']);
    }

    public function test_malformed_tin_is_rejected(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('tin', '12')
            ->call('save')
            ->assertHasErrors(['tin']);
    }
}
