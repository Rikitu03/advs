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
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('signature.create'));

        $user->refresh();
        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertSame('Negofood Trading', $user->vendor->company_name);
        $this->assertNull($user->vendor->representative->government_id_type);
        $this->assertNull($user->vendor->representative->government_id_number);
        $this->assertNull($user->vendor->representative->home_address);
    }

    public function test_business_step_does_not_render_legacy_identity_or_home_address_fields(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $this->actingAs($user)
            ->get(route('business.create'))
            ->assertOk()
            ->assertDontSee('Government ID type')
            ->assertDontSee('Government ID number')
            ->assertDontSee('Home address (complete)');
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

    public function test_vendor_without_a_profile_is_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business.create'));
        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_signature_gate_does_not_fire_before_the_profile_is_complete(): void
    {
        // No profile and no signature: the business gate wins; the user must not
        // be bounced to the signature step yet.
        $user = User::factory()->withoutVendorProfile()->unenrolled()->create();

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_complete_profile_but_unenrolled_vendor_is_gated_to_signature(): void
    {
        $user = User::factory()->unenrolled()->create(); // profile complete by default

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('signature.create'));
    }

    public function test_livewire_endpoints_are_never_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $response = $this->actingAs($user)->post(route('default-livewire.update'));

        $this->assertNotSame(
            route('business.create'),
            $response->headers->get('Location'),
            'Livewire update requests must not be redirected by the profile gate.'
        );
    }

    public function test_officers_and_admins_are_never_gated_to_the_business_step(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->withoutVendorProfile()->create();

        $this->actingAs($officer)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_guests_cannot_access_the_business_step(): void
    {
        $this->get(route('business.create'))->assertRedirect('/login');
    }
}
