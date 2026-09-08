<?php

namespace Tests\Unit\Services\Document;

use App\Models\Vendor;
use App\Services\Document\BusinessPermitEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPermitEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_available_permit_identity_evidence_and_normalizes_city_aliases(): void
    {
        $vendor = new Vendor([
            'company_name' => 'Acme Foods Inc',
            'trade_name' => 'Acme Kitchen',
            'business_permit_number' => 'BP-2026-99',
            'business_street' => '12 Rizal Street',
            'business_barangay' => 'Barangay San Lorenzo',
            'business_city' => 'Makati',
            'business_province' => 'Metro Manila',
            'nature_of_business' => 'Transport Service',
        ]);

        $result = (new BusinessPermitEvidence)->score([
            'issuer_city_canonical' => 'Makati',
            'fields' => [
                'permit_no' => ['value' => 'BP/2026/99', 'confidence' => 94, 'source_page' => 1],
                'owner_or_proprietor' => ['value' => 'Acme Foods Inc', 'confidence' => 90, 'source_page' => 1],
                'trade_name' => ['value' => 'Acme Kitchen', 'confidence' => 90, 'source_page' => 1],
                'business_address' => ['value' => '12 Rizal Street, Barangay San Lorenzo, Makati City', 'confidence' => 88, 'source_page' => 1],
                'nature_of_business' => ['value' => 'Transport Service', 'confidence' => 91, 'source_page' => 1],
                'valid_until' => ['value' => 'December 31, 2099', 'confidence' => 95, 'source_page' => 1],
            ],
        ], $vendor);

        $this->assertSame(1.0, $result['score']);
        $this->assertSame('valid', $result['validity']);
        $this->assertTrue($result['checks']['city']['matched']);
        $this->assertSame([], $result['flags']);
    }

    public function test_missing_optional_evidence_is_unavailable_not_a_mismatch(): void
    {
        $vendor = new Vendor(['company_name' => 'Acme Foods']);

        $result = (new BusinessPermitEvidence)->score([
            'issuer_city_canonical' => 'Makati',
            'fields' => [],
        ], $vendor);

        $this->assertNull($result['score']);
        $this->assertFalse($result['checks']['permit_number']['available']);
        $this->assertNotContains('business_permit_mismatch', $result['flags']);
        $this->assertContains('business_permit_validity_unavailable', $result['flags']);
    }
}
