<?php

namespace Tests\Feature\Vendor;

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVendorProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Negofood Trading',
            'trade_name' => '',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026001',
            'sec_registration_number' => '',
            'business_permit_number' => 'BP-2026-555',
            'nature_of_business' => 'Food retail',
            'business_street' => '12 Ortigas Ave',
            'business_barangay' => 'Barangay San Antonio',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
            'first_name' => 'Jose',
            'middle_name' => '',
            'last_name' => 'Rizal',
            'suffix' => '',
            'date_of_birth' => '1990-06-19',
            'gender' => 'male',
            'contact_number' => '+63 917 000 0000',
            'government_id_type' => 'national_id',
            'government_id_number' => '1234-5678-9012',
            'home_address' => '37 Real St, Calamba',
        ], $overrides);
    }

    public function test_it_creates_vendor_and_representative_and_marks_profile_complete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload());

        $this->assertSame('Negofood Trading', $vendor->company_name);
        $this->assertSame('sole_proprietorship', $vendor->business_entity_type);
        $this->assertSame('DTI-2026001', $vendor->dti_registration_number);
        $this->assertNull($vendor->sec_registration_number);
        $this->assertNull($vendor->trade_name);
        $this->assertSame('Jose', $vendor->representative->first_name);
        $this->assertNull($vendor->representative->middle_name);
        $this->assertTrue($user->refresh()->hasCompletedVendorProfile());
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
    }

    public function test_it_nullifies_dti_number_for_a_corporation(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload([
            'business_entity_type' => 'corporation',
            'dti_registration_number' => 'DTI-LEFTOVER',
            'sec_registration_number' => 'SEC-CS202600123',
        ]));

        $this->assertNull($vendor->dti_registration_number);
        $this->assertSame('SEC-CS202600123', $vendor->sec_registration_number);
    }

    public function test_it_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();
        $action = app(CreateVendorProfile::class);

        $action->execute($user, $this->payload());
        $action->execute($user, $this->payload(['company_name' => 'Renamed Trading']));

        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
        $this->assertSame('Renamed Trading', $user->vendor->refresh()->company_name);
    }
}
