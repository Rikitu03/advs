<?php

namespace Tests\Unit;

use App\Models\Vendor;
use Tests\TestCase;

class VendorBusinessProfileTest extends TestCase
{
    public function test_entity_requires_dti_only_for_sole_proprietorship(): void
    {
        $this->assertTrue(Vendor::entityRequiresDti('sole_proprietorship'));
        $this->assertFalse(Vendor::entityRequiresDti('corporation'));
        $this->assertFalse(Vendor::entityRequiresDti(null));
    }

    public function test_factory_uses_null_for_the_legacy_business_permit_number(): void
    {
        $this->assertNull(Vendor::factory()->definition()['business_permit_number']);
    }

    public function test_business_address_accessor_joins_structured_parts(): void
    {
        $vendor = new Vendor([
            'business_street' => '123 Mabini St',
            'business_barangay' => 'Barangay San Jose',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
        ]);

        $this->assertSame(
            '123 Mabini St, Barangay San Jose, Pasig, Metro Manila, 1600',
            $vendor->business_address,
        );
    }
}
