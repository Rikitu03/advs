<?php

namespace Tests\Feature\Vendor;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorRepresentativeRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_has_one_representative(): void
    {
        $vendor = Vendor::factory()->create();
        $rep = VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Cruz',
            'suffix' => 'Jr.',
        ]);

        $this->assertTrue($vendor->refresh()->representative->is($rep));
        $this->assertTrue($rep->vendor->is($vendor));
        $this->assertSame('Maria Santos Cruz Jr.', $rep->fullName());
    }
}
