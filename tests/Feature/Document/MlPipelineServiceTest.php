<?php

namespace Tests\Feature\Document;

use App\Models\Document;
use App\Services\Document\MlPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
                'fields' => [
                    'tin' => ['name' => 'TIN', 'value' => '009-028-463-000', 'required' => true,
                        'matched' => true, 'confidence' => 96.0, 'warnings' => []],
                ],
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

        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'national',
            'logo_reference_ids' => ['' => 42],
        ]);
        $columns = $mapped['columns'];

        $this->assertSame('BIR Permit', $columns['classification_label']);
        $this->assertEqualsWithDelta(0.95, $columns['classification_confidence'], 1e-6);
        $this->assertSame('BUREAU OF INTERNAL REVENUE', $columns['ocr_extracted_text']);
        // The structured field map is persisted verbatim — warnings included —
        // for the officer drill-down's key/value panel.
        $this->assertSame(
            ['name' => 'TIN', 'value' => '009-028-463-000', 'required' => true,
                'matched' => true, 'confidence' => 96.0, 'warnings' => []],
            $columns['ocr_fields']['tin'],
        );
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cityCasings(): array
    {
        return [
            'header caps' => ['DIGOS', 'Digos'],
            'multi-word' => ['GENERAL SANTOS', 'General Santos'],
            'doubled spacing' => ["General  \tSantos ", 'General Santos'],
        ];
    }

    /**
     * The stored city is half of the unique (document_type_id, city) issuer key, so
     * every casing/spacing OCR can produce has to land on one canonical value.
     */
    #[DataProvider('cityCasings')]
    public function test_maps_the_ocr_issuing_city_to_detected_city(string $read, string $stored): void
    {
        $mapped = $this->service()->mapStages($this->ocrStageWithCity([
            'name' => 'City Issued', 'value' => $read, 'required' => true,
            'matched' => true, 'confidence' => 88.0, 'warnings' => [],
        ]));

        $this->assertSame($stored, $mapped['columns']['detected_city']);
    }

    /**
     * An unread city must leave the column ALONE, not blank it: a re-run whose OCR
     * degraded would otherwise erase the city an earlier run read off the same
     * document — and '' is the national-issuer sentinel, not "no city".
     */
    public function test_an_unmatched_city_leaves_detected_city_untouched(): void
    {
        $mapped = $this->service()->mapStages($this->ocrStageWithCity([
            'name' => 'City Issued', 'value' => null, 'required' => true,
            'matched' => false, 'confidence' => 0.0, 'warnings' => [],
        ]));

        $this->assertArrayNotHasKey('detected_city', $mapped['columns']);
    }

    public function test_a_document_type_with_no_city_field_sets_no_detected_city(): void
    {
        $mapped = $this->service()->mapStages(['ocr' => ['pages' => [[
            'text' => 'BUREAU OF INTERNAL REVENUE', 'fields' => [], 'quality' => [],
        ]]]]);

        $this->assertArrayNotHasKey('detected_city', $mapped['columns']);
    }

    /**
     * A Stage-2 payload carrying one `city_issued` field, shaped as the API returns it.
     *
     * @param  array<string, mixed>  $cityField
     * @return array<string, mixed>
     */
    private function ocrStageWithCity(array $cityField): array
    {
        return ['ocr' => ['pages' => [[
            'text' => 'CITY OF DIGOS',
            'fields' => ['city_issued' => $cityField],
            'quality' => ['mean_confidence' => 90.0, 'flags' => []],
        ]]]];
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

    public function test_persists_the_stage_4b_tamper_verdict_without_an_issuer_reference(): void
    {
        $stages = [
            'stamp' => [
                'match' => false,
                'reason' => 'unreferenced_logo',
                'similarity_score' => null,
                'stamp_tampered' => true,
                'genuine_probability' => 0.05,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'lgu']);

        $this->assertTrue($mapped['columns']['stamp_tampered']);
        $this->assertFalse($mapped['columns']['stamp_detected']);
        $this->assertContains('unreferenced_logo', $mapped['flags']);
    }

    public function test_leaves_the_tamper_verdict_untouched_when_the_classifier_did_not_run(): void
    {
        $stages = [
            'stamp' => [
                'match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'stamp_tampered' => null, 'genuine_probability' => null,
            ],
        ];

        $mapped = $this->service()->mapStages($stages, ['issuer_scope' => 'national']);

        // Null is "could not run", not "clean" — never overwrite an earlier verdict.
        $this->assertArrayNotHasKey('stamp_tampered', $mapped['columns']);
    }

    public function test_logo_reference_id_follows_the_city_the_api_matched(): void
    {
        $stages = [
            'stamp' => ['match' => true, 'similarity_score' => 0.95, 'reason' => null,
                'city' => 'Pasig City', 'stamp_tampered' => false],
        ];

        $mapped = $this->service()->mapStages($stages, [
            'issuer_scope' => 'lgu',
            'logo_reference_ids' => ['Pasig City' => 7, 'Makati' => 9],
        ]);

        $this->assertSame(7, $mapped['columns']['logo_reference_id']);
    }

    /**
     * A region YOLOv8 actually found (signature_bbox populated) but the vendor has
     * no enrolled reference yet must be distinguishable from a genuine detection
     * miss — it should flag as a missing reference, not "no signature detected".
     */
    public function test_skipped_signature_with_no_reference_flags_distinctly_from_a_detection_miss(): void
    {
        $stages = [
            'detection' => ['detections' => [
                ['label' => 'signature', 'confidence' => 0.9, 'box' => [10, 20, 30, 40]],
            ], 'flags' => []],
            'signature' => ['skipped' => true, 'reason' => 'no_reference_embedding'],
        ];

        $mapped = $this->service()->mapStages($stages);

        $this->assertSame([10, 20, 30, 40], $mapped['columns']['signature_bbox']);
        $this->assertFalse($mapped['columns']['signature_detected']);
        $this->assertContains('no_signature_reference', $mapped['flags']);
        $this->assertNotContains('no_signature_detected', $mapped['flags']);
    }

    public function test_skipped_signature_with_a_genuine_detection_miss_does_not_flag_no_reference(): void
    {
        $stages = [
            'detection' => ['detections' => [], 'flags' => ['no_signature_detected']],
            'signature' => ['skipped' => true, 'reason' => 'no_signature_detected'],
        ];

        $mapped = $this->service()->mapStages($stages);

        $this->assertNull($mapped['columns']['signature_bbox']);
        $this->assertFalse($mapped['columns']['signature_detected']);
        $this->assertNotContains('no_signature_reference', $mapped['flags']);
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templatePerTypeProvider(): array
    {
        return [
            'BIR certificate → bir' => ['bir_certificate', 'bir'],
            'Business Permit → business_permit' => ['business_permit', 'business_permit'],
            'DTI registration → dti' => ['dti_registration', 'dti'],
        ];
    }

    #[DataProvider('templatePerTypeProvider')]
    public function test_validate_sends_the_ocr_template_matching_the_document_type(string $code, string $template): void
    {
        $typeId = DB::table('document_types')->insertGetId([
            'name' => $code, 'code' => $code, 'issuer_scope' => 'national',
            'is_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = $this->document(['document_type_id' => $typeId]);
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []], 200)]);

        $this->service()->validate($document);

        Http::assertSent(fn (Request $request): bool => $this->multipartField($request->body(), 'template') === $template);
    }

    public function test_validate_falls_back_to_the_configured_template_for_an_unmapped_type(): void
    {
        config()->set('advs.ml.template', 'bir');
        $typeId = DB::table('document_types')->insertGetId([
            'name' => 'Sanitary Permit', 'code' => 'sanitary_permit', 'issuer_scope' => 'lgu',
            'is_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = $this->document(['document_type_id' => $typeId]);
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []], 200)]);

        $this->service()->validate($document);

        Http::assertSent(fn (Request $request): bool => $this->multipartField($request->body(), 'template') === 'bir');
    }

    /**
     * Read one multipart form-data field's value out of a raw request body.
     */
    private function multipartField(string $body, string $name): ?string
    {
        // Guzzle inserts a Content-Length header between the disposition and the
        // value, so skip any intervening part headers to the blank-line separator.
        return preg_match('/name="'.preg_quote($name, '/').'".*?\r\n\r\n(.*?)\r\n/s', $body, $m) === 1
            ? $m[1]
            : null;
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
                && str_contains($body, '{"":[0.4,0.5]}')  // stamp_references, '' = national sentinel
                && $this->multipartField($body, 'issuer_scope') === 'national'
                && str_contains($body, 'name="forensics"');
        });
    }

    public function test_sends_every_city_reference_for_an_lgu_issuer(): void
    {
        Http::fake(['*/v1/validate' => Http::response(['stages' => [], 'flags' => []])]);

        $typeId = DB::table('document_types')->insertGetId([
            'name' => 'Business Permit', 'code' => 'business_permit',
            'issuer_scope' => 'lgu', 'is_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['Pasig City', [0.1, 0.2]], ['Makati', [0.3, 0.4]]] as [$city, $vector]) {
            DB::table('logo_references')->insert([
                'document_type_id' => $typeId, 'city' => $city, 'label' => $city,
                'feature_vector' => json_encode($vector),
                'reference_image_path' => 'refs/'.Str::slug($city).'.png',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->service()->validate($this->document(['document_type_id' => $typeId]));

        // multipartField() is this file's existing helper for reading one
        // form-data field out of the raw Guzzle body.
        Http::assertSent(function (Request $request) {
            $body = $request->body();

            // Loose comparison: the map is a JSON object the API looks up by key,
            // and the query returns the rows in (document_type_id, city) index
            // order — so which city comes first is not part of the contract.
            return $this->multipartField($body, 'issuer_scope') === 'lgu'
                && json_decode($this->multipartField($body, 'stamp_references'), true)
                    == ['Pasig City' => [0.1, 0.2], 'Makati' => [0.3, 0.4]];
        });
    }
}
