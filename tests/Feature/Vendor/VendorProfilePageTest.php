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
            ->assertSee('Jose Rizal')
            ->assertSee('Pasig');
    }
}
