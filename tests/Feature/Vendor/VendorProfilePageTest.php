<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_declared_business_and_owner_data(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->for($user)->create([
            'company_name' => 'Negofood Trading',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'business_city' => 'Pasig',
            'sec_registration_number' => 'SEC-CS202600123',
            'business_permit_number' => 'BP-2026-555',
        ]);
        VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Jose',
            'middle_name' => null,
            'suffix' => null,
            'last_name' => 'Rizal',
            'government_id_type' => 'national_id',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertSee('Negofood Trading')
            ->assertSee('123-456-789-000')
            ->assertSee('Sole Proprietorship')
            ->assertSee('SEC-CS202600123')
            ->assertSee('BP-2026-555')
            ->assertSee('Jose Rizal')
            ->assertSee('Pasig');
    }

    public function test_profile_page_hides_uncollected_legacy_representative_fields(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->for($user)->create();
        VendorRepresentative::factory()->for($vendor)->create([
            'government_id_type' => null,
            'government_id_number' => null,
            'home_address' => null,
        ]);

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertDontSee('Government ID')
            ->assertDontSee('Home address');
    }

    public function test_profile_page_hides_an_empty_legacy_business_permit_number(): void
    {
        $user = User::factory()->create();
        Vendor::factory()->for($user)->create(['business_permit_number' => null]);

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertDontSee('Business Permit No.');
    }
}
