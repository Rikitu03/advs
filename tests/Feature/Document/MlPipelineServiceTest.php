<?php

namespace Tests\Feature\Document;

use App\Models\Document;
use App\Services\Document\MlPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MlPipelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MlPipelineService
    {
        return app(MlPipelineService::class);
    }

    private function document(array $overrides = []): Document
    {
        Storage::fake('local');
        $document = Document::factory()->create($overrides);
        Storage::disk('local')->put($document->file_path, 'bytes');

        return $document;
    }

    // ── mapStages(): pure projection + signature calibration ──────────────────

    public function test_maps_clean_stages_to_columns(): void
    {
        $stages = [
            'classification' => ['label' => 'BIR Permit', 'confidence' => 0.95, 'passed_threshold' => true],
            'ocr' => ['pages' => [[
                'text' => 'BUREAU OF INTERNAL REVENUE',
                'quality' => ['mean_confidence' => 92.0, 'text_validation_score' => 1.0,
                    'required_matched' => 3, 'required_total' => 3, 'flags' => []],
            ]]],
            'detection' => ['detections' => [
                ['label' => 'signature', 'confidence' => 0.9, 'box' => [10, 20, 30, 40]],
                ['label' => 'stamp', 'confidence' => 0.8, 'box' => [50, 60, 70, 80]],
            ], 'flags' => []],
            'signature' => ['match' => true, 'distance' => 0.7, 'threshold' => 1.243976],
            'stamp' => ['match' => true, 'similarity_score' => 0.95, 'reason' => null],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'national', 'logo_reference_id' => 42]);
        $columns = $mapped['columns'];

        $this->assertSame('BIR Permit', $columns['classification_label']);
        $this->assertEqualsWithDelta(0.95, $columns['classification_confidence'], 1e-6);
        $this->assertSame('BUREAU OF INTERNAL REVENUE', $columns['ocr_extracted_text']);
        $this->assertEqualsWithDelta(1.0, $columns['text_validation_score'], 1e-6);
        $this->assertSame(3, $columns['text_fields_matched']);
        $this->assertSame([10, 20, 30, 40], $columns['signature_bbox']);
        $this->assertSame([50, 60, 70, 80], $columns['stamp_bbox']);

        $this->assertTrue($columns['signature_detected']);
        $this->assertTrue($columns['signature_passed']);
        // Calibrated authenticity, NOT raw similarity: 1 - 0.7/(2*1.243976) ≈ 0.7186.
        $this->assertEqualsWithDelta(0.7186, $columns['signature_score'], 0.0005);

        $this->assertTrue($columns['stamp_detected']);
        $this->assertEqualsWithDelta(0.95, $columns['stamp_score'], 1e-6);
        $this->assertSame(42, $columns['logo_reference_id']);
        $this->assertSame([], $mapped['flags']);
    }

    public function test_signature_calibration_uses_distance_and_flags_mismatch(): void
    {
        // Forged pair: distance well past the EER threshold.
        $stages = ['signature' => ['match' => false, 'distance' => 1.697, 'threshold' => 1.243976]];

        $mapped = $this->service()->mapStages($stages);

        // 1 - 1.697/(2*1.243976) ≈ 0.3179 — far below the genuine band.
        $this->assertEqualsWithDelta(0.3179, $mapped['columns']['signature_score'], 0.0005);
        $this->assertFalse($mapped['columns']['signature_passed']);
        $this->assertContains('signature_mismatch', $mapped['flags']);
    }

    public function test_skipped_stamp_with_null_issuer_scope_flags_no_issuer_logo(): void
    {
        $stages = ['stamp' => ['skipped' => true, 'reason' => 'no_stamp_detected']];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => null]);

        $this->assertFalse($mapped['columns']['stamp_detected']);
        $this->assertContains('no_issuer_logo', $mapped['flags']);
        $this->assertNotContains('no_stamp_detected', $mapped['flags']);
    }

    public function test_unreferenced_logo_flag_for_national_issuer(): void
    {
        $stages = ['stamp' => ['match' => false, 'reason' => 'unreferenced_logo', 'similarity_score' => null]];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'national']);

        $this->assertContains('unreferenced_logo', $mapped['flags']);
    }

    // ── validate(): transport + reference gathering ───────────────────────────

    public function test_validate_returns_stages_and_flags(): void
    {
        Http::fake(['*/v1/validate' => Http::response([
            'stages' => ['classification' => ['label' => 'X', 'confidence' => 0.9]],
            'flags' => ['low_classification_confidence'],
        ], 200)]);

        $response = $this->service()->validate($this->document());

        $this->assertArrayHasKey('classification', $response['stages']);
        $this->assertSame(['low_classification_confidence'], $response['flags']);
    }

    public function test_validate_throws_on_http_error(): void
    {
        Http::fake(['*/v1/validate' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);

        $this->service()->validate($this->document());
    }

    public function test_validate_sends_issuer_references_for_a_national_type(): void
    {
        $typeId = DB::table('document_types')->insertGetId([
            'name' => 'BIR Permit', 'code' => 'bir_permit', 'issuer_scope' => 'national',
            'is_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = $this->document(['document_type_id' => $typeId]);
        DB::table('vendor_embeddings')->insert([
            'vendor_id' => $document->vendor_id, 'signature_embedding' => json_encode([0.1, 0.2, 0.3]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logo_references')->insert([
            'document_type_id' => $typeId, 'city' => '', 'feature_vector' => json_encode([0.4, 0.5]),
            'reference_image_path' => 'refs/bir.png', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []], 200)]);

        $this->service()->validate($document);

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return str_contains($body, 'name="document_type"')
                && str_contains($body, 'bir_permit')
                && str_contains($body, '[0.1,0.2,0.3]')   // signature_reference
                && str_contains($body, '[0.4,0.5]')       // stamp_reference (national, city '')
                && str_contains($body, 'name="forensics"');
        });
    }
}
