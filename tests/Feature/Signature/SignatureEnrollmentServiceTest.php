<?php

namespace Tests\Feature\Signature;

use App\Services\Signature\SignatureEnrollmentResult;
use App\Services\Signature\SignatureEnrollmentService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SignatureEnrollmentServiceTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apiBody(array $overrides = []): array
    {
        return array_replace([
            'signatures' => [
                ['box' => [10, 10, 120, 60], 'confidence' => 0.95, 'embedding' => [0.1, 0.2, 0.3]],
                ['box' => [10, 80, 120, 130], 'confidence' => 0.90, 'embedding' => [0.1, 0.2, 0.3]],
                ['box' => [10, 150, 120, 200], 'confidence' => 0.88, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'count' => 3,
            'consistency' => 0.92,
            'centroid' => [0.1, 0.2, 0.3],
            'forensics' => ['hard_flag' => false, 'reasons' => [], 'techniques' => []],
        ], $overrides);
    }

    private function fakeEnroll(array $overrides = [], int $status = 200): void
    {
        Http::fake([
            '*/v1/signature/enroll' => Http::response($this->apiBody($overrides), $status),
        ]);
    }

    private function enroll(): SignatureEnrollmentResult
    {
        return app(SignatureEnrollmentService::class)->enroll('fake-bytes', 'signatures.jpg');
    }

    public function test_three_consistent_untampered_signatures_are_accepted(): void
    {
        $this->fakeEnroll();

        $result = $this->enroll();

        $this->assertTrue($result->accepted);
        $this->assertNull($result->reason);
        $this->assertSame(3, $result->count);
        $this->assertEqualsWithDelta(0.92, $result->consistency, 1e-9);
        $this->assertSame([0.1, 0.2, 0.3], $result->centroid);
        $this->assertCount(3, $result->samples);
    }

    public function test_wrong_signature_count_is_rejected(): void
    {
        $this->fakeEnroll(['count' => 2, 'signatures' => [], 'consistency' => null]);

        $result = $this->enroll();

        $this->assertFalse($result->accepted);
        $this->assertSame(SignatureEnrollmentService::REASON_COUNT, $result->reason);
        $this->assertSame(2, $result->count);
    }

    public function test_inconsistent_signatures_are_rejected(): void
    {
        $this->fakeEnroll(['consistency' => 0.51]);

        $result = $this->enroll();

        $this->assertFalse($result->accepted);
        $this->assertSame(SignatureEnrollmentService::REASON_SIMILARITY, $result->reason);
    }

    public function test_edited_or_pasted_image_is_rejected(): void
    {
        $this->fakeEnroll([
            'forensics' => [
                'hard_flag' => true,
                'reasons' => ["File last written by an image editor ('photoshop')"],
                'techniques' => [],
            ],
        ]);

        $result = $this->enroll();

        $this->assertFalse($result->accepted);
        $this->assertSame(SignatureEnrollmentService::REASON_EDITED, $result->reason);
    }

    public function test_count_gate_wins_over_consistency_and_forensics(): void
    {
        // A photo that fails multiple gates surfaces the earliest (count) reason,
        // so the user is told the most actionable thing first.
        $this->fakeEnroll(['count' => 5, 'consistency' => 0.10, 'forensics' => ['hard_flag' => true, 'reasons' => [], 'techniques' => []]]);

        $this->assertSame(SignatureEnrollmentService::REASON_COUNT, $this->enroll()->reason);
    }

    public function test_transport_failure_throws(): void
    {
        $this->fakeEnroll(status: 503);

        $this->expectException(RuntimeException::class);
        $this->enroll();
    }
}
