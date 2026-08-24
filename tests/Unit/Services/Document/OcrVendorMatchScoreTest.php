<?php

namespace Tests\Unit\Services\Document;

use App\Models\Vendor;
use App\Services\Document\OcrVendorMatchScore;
use PHPUnit\Framework\TestCase;

class OcrVendorMatchScoreTest extends TestCase
{
    public function test_it_matches_case_and_separator_variants_safely(): void
    {
        $vendor = new Vendor([
            'company_name' => 'ACME (PH), Inc.',
            'trade_name' => 'A+B Foods',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026-001',
            'sec_registration_number' => null,
            'business_permit_number' => 'BP/26.99',
            'registration_number' => null,
        ]);

        $result = (new OcrVendorMatchScore)->calculate(
            'Registered to acme ph inc. Trade name: A+B FOODS. TIN 123456789000. DTI 2026 001. Permit BP-26-99.',
            $vendor,
        );

        $this->assertSame(5, $result['matched']);
        $this->assertSame(5, $result['expected']);
        $this->assertEqualsWithDelta(1.0, $result['score'], 1e-6);
    }

    public function test_it_does_not_match_an_identifier_embedded_in_a_longer_value(): void
    {
        $vendor = new Vendor([
            'company_name' => 'Acme Foods',
            'tin' => '123-456',
        ]);

        $result = (new OcrVendorMatchScore)->calculate(
            'ACME FOODS TIN 91234567',
            $vendor,
        );

        $this->assertSame(1, $result['matched']);
        $this->assertSame(2, $result['expected']);
        $this->assertSame(0.5, $result['score']);
    }

    public function test_it_ignores_empty_profile_fields_and_marks_missing_ocr_unavailable(): void
    {
        $vendor = new Vendor([
            'company_name' => 'Acme Foods',
            'trade_name' => '   ',
            'tin' => null,
        ]);

        $result = (new OcrVendorMatchScore)->calculate(null, $vendor);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['expected']);
        $this->assertNull($result['score']);
    }

    public function test_it_marks_a_missing_vendor_profile_unavailable(): void
    {
        $result = (new OcrVendorMatchScore)->calculate('ACME FOODS', null);

        $this->assertSame(['score' => null, 'matched' => 0, 'expected' => 0], $result);
    }
}
