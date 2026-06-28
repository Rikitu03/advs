<?php

namespace Tests\Feature\Document;

use App\Services\Document\RiskScoreService;
use Tests\TestCase;

class RiskScoreServiceTest extends TestCase
{
    private function service(): RiskScoreService
    {
        return new RiskScoreService;
    }

    public function test_clean_document_scores_low(): void
    {
        $result = $this->service()->compute([
            'text' => 0.95,
            'classification' => 0.98,
            'signature' => 0.92,
            'stamp' => 0.95,
            'tamper_authenticity' => 0.97,
            'tamper_confidence' => 0.05,
        ]);

        $this->assertSame('low', $result['level']);
        $this->assertFalse($result['hard_override']);
        $this->assertLessThan(31, $result['score']);
    }

    public function test_five_term_weighted_formula_is_applied(): void
    {
        // Weights default to 0.20 each; risk = Σ w(1-score)*100.
        // (1-0.9)+(1-0.9)+(1-0.4)+(1-0.9)+(1-1.0) = 0.9 ; *0.2 *100 / 1.0 = 18.
        $result = $this->service()->compute([
            'text' => 0.9,
            'classification' => 0.9,
            'signature' => 0.4,
            'stamp' => 0.9,
            'tamper_authenticity' => 1.0,
            'tamper_confidence' => 0.0,
        ]);

        $this->assertEqualsWithDelta(18.0, $result['score'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['penalties'], 0.01);
    }

    public function test_missing_component_adds_penalty_and_renormalises(): void
    {
        $result = $this->service()->compute([
            'text' => 1.0,
            'classification' => 1.0,
            'signature' => null,   // missing -> penalty, excluded from blend
            'stamp' => 1.0,
            'tamper_authenticity' => 1.0,
            'tamper_confidence' => 0.0,
        ]);

        // Blend is 0 (all present components clean); only the missing penalty remains.
        $this->assertEqualsWithDelta(15.0, $result['score'], 0.01);
        $this->assertTrue($result['breakdown']['signature']['missing']);
    }

    public function test_high_confidence_tamper_hard_overrides_to_high(): void
    {
        $result = $this->service()->compute([
            'text' => 0.99,
            'classification' => 0.99,
            'signature' => 0.99,
            'stamp' => 0.99,
            'tamper_authenticity' => 0.40,
            'tamper_confidence' => 0.85, // >= 0.80 hard threshold
        ]);

        $this->assertTrue($result['hard_override']);
        $this->assertSame('high', $result['level']);
        $this->assertGreaterThanOrEqual(61, $result['score']);
    }

    public function test_forensics_skipped_does_not_penalise(): void
    {
        // tamper_authenticity null (forensics skipped) must not add a penalty,
        // unlike a missing ML component.
        $result = $this->service()->compute([
            'text' => 1.0,
            'classification' => 1.0,
            'signature' => 1.0,
            'stamp' => 1.0,
            'tamper_authenticity' => null,
            'tamper_confidence' => 0.0,
        ]);

        $this->assertEqualsWithDelta(0.0, $result['score'], 0.01);
        $this->assertArrayNotHasKey('tamper', $result['breakdown']);
    }
}
