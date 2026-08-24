<?php

namespace Tests\Feature\Document;

use App\Jobs\EnrollReferenceJob;
use App\Models\Document;
use App\Models\User;
use App\Models\ValidationResult;
use App\Services\Document\MlPipelineService;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 4b reference seeding (ADVS_System_Reference.md §5 Stage 4b): the first
 * officer-APPROVED document carrying an issuer's logo becomes that issuer's reference.
 * References are keyed by issuer — document_type for `national`, document_type + city
 * for `lgu` — never per vendor.
 */
class EnrollReferenceJobTest extends TestCase
{
    use RefreshDatabase;

    private const VECTOR = [0.11, 0.22, 0.33];

    protected function setUp(): void
    {
        parent::setUp();

        // issuer_scope per type is what routes this job; seed the real taxonomy.
        $this->seed(DocumentTypeSeeder::class);
    }

    private function fakeEmbed(array $vector = self::VECTOR): void
    {
        Http::fake([
            '*/v1/stamp/embed' => Http::response([
                'vector' => $vector,
                'crop_png_base64' => base64_encode('cropped-logo-png-bytes'),
            ], 200),
        ]);
    }

    /**
     * A completed document of the given type with a detected stamp box.
     */
    private function document(string $typeCode, array $resultOverrides = []): Document
    {
        Storage::fake('local');

        $typeId = DB::table('document_types')->where('code', $typeCode)->value('id');
        $this->assertNotNull($typeId, "document type [{$typeCode}] should be seeded");

        $document = Document::factory()->create(['document_type_id' => $typeId]);
        Storage::disk('local')->put($document->file_path, 'document-bytes');

        ValidationResult::factory()->create(array_merge([
            'document_id' => $document->id,
            'submission_id' => $document->submission_id,
            'stamp_bbox' => [10.0, 20.0, 110.0, 120.0],
        ], $resultOverrides));

        return $document->fresh();
    }

    private function officer(): User
    {
        return User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
    }

    private function runJob(Document $document, ?User $officer = null): void
    {
        (new EnrollReferenceJob($document, ($officer ?? $this->officer())->id))
            ->handle(app(MlPipelineService::class));
    }

    // ── national issuers ──────────────────────────────────────────────────────

    public function test_seeds_a_national_issuer_reference_keyed_by_document_type(): void
    {
        $this->fakeEmbed();
        $document = $this->document('bir_certificate');
        $officer = $this->officer();

        $this->runJob($document, $officer);

        $row = DB::table('logo_references')->first();
        $this->assertNotNull($row);
        // '' sentinel, not NULL — the unique index only constrains national issuers
        // to one row per document type because city is never NULL.
        $this->assertSame('', $row->city);
        $this->assertSame($document->document_type_id, (int) $row->document_type_id);
        $this->assertSame(self::VECTOR, json_decode($row->feature_vector, true));
        $this->assertSame($document->id, (int) $row->seeded_from_document_id);
        $this->assertSame($officer->id, (int) $row->enrolled_by);
        $this->assertNotNull($row->enrolled_at);

        Storage::disk('local')->assertExists($row->reference_image_path);
        $this->assertSame('cropped-logo-png-bytes', Storage::disk('local')->get($row->reference_image_path));
    }

    public function test_sends_the_detected_stamp_box_so_the_api_crops_the_logo(): void
    {
        $this->fakeEmbed();
        $document = $this->document('bir_certificate');

        $this->runJob($document);

        Http::assertSent(function ($request): bool {
            $box = collect($request->data())->firstWhere('name', 'box')['contents'] ?? null;

            // json_encode drops a float's trailing ".0", so the wire carries ints —
            // which is all the API needs (_parse_box does int(float(v))).
            return str_contains($request->url(), '/v1/stamp/embed')
                && json_decode((string) $box, true) === [10, 20, 110, 120];
        });
    }

    public function test_is_idempotent_when_the_issuer_already_has_a_reference(): void
    {
        $this->fakeEmbed();
        $first = $this->document('bir_certificate');
        $this->runJob($first);

        // A second approved BIR document must not replace or duplicate the reference.
        $second = $this->document('bir_certificate');
        $this->runJob($second);

        $this->assertDatabaseCount('logo_references', 1);
        $this->assertSame(
            $first->id,
            (int) DB::table('logo_references')->value('seeded_from_document_id'),
        );
    }

    // ── skip paths (each is fail-forward, never an exception) ──────────────────

    public function test_skips_document_types_with_no_issuer_logo(): void
    {
        $this->fakeEmbed();
        // signed_contract has issuer_scope = null — no logo is expected at all.
        $this->runJob($this->document('signed_contract'));

        $this->assertDatabaseCount('logo_references', 0);
        Http::assertNothingSent();
    }

    public function test_skips_lgu_issuers_until_the_city_is_known(): void
    {
        $this->fakeEmbed();
        // business_permit is `lgu`; without detected_city the issuer cannot be keyed.
        $this->runJob($this->document('business_permit', ['detected_city' => null]));

        $this->assertDatabaseCount('logo_references', 0);
        Http::assertNothingSent();
    }

    public function test_seeds_an_lgu_issuer_keyed_by_document_type_and_city(): void
    {
        $this->fakeEmbed();
        $this->runJob($this->document('business_permit', ['detected_city' => 'Makati']));

        $row = DB::table('logo_references')->first();
        $this->assertNotNull($row);
        $this->assertSame('Makati', $row->city);
    }

    /**
     * The seam between Stage 2 and Stage 4b, end to end and unmocked.
     *
     * The two cases above set `detected_city` by hand, so they passed for months while
     * nothing in production ever wrote that column and no LGU issuer could be seeded.
     * This one takes the city from where it really comes — a `/v1/validate` OCR payload
     * projected by {@see MlPipelineService::mapStages()} — so the column having no
     * writer would fail the test instead of hiding in it.
     */
    public function test_seeds_an_lgu_issuer_from_the_city_stage_2_actually_read(): void
    {
        $this->fakeEmbed();
        $document = $this->document('business_permit', ['detected_city' => null]);

        // A whole response, not just the OCR stage: mapStages derives the bbox columns
        // from `detection` unconditionally, so an OCR-only payload would blank the very
        // stamp_bbox this job seeds from.
        $mapped = app(MlPipelineService::class)->mapStages([
            'ocr' => ['pages' => [[
                'text' => 'CITY OF DIGOS',
                'fields' => ['city_issued' => [
                    'name' => 'City Issued', 'value' => 'DIGOS', 'required' => true,
                    'matched' => true, 'confidence' => 88.0, 'warnings' => [],
                ]],
                'quality' => ['mean_confidence' => 90.0, 'flags' => []],
            ]]],
            'detection' => ['detections' => [
                ['label' => 'stamp', 'confidence' => 0.8, 'box' => [10, 20, 110, 120]],
            ], 'flags' => []],
        ]);
        $document->validationResult->fill($mapped['columns'])->save();

        $this->runJob($document->fresh());

        $row = DB::table('logo_references')->first();
        $this->assertNotNull($row, 'Stage 2 read a city, so the LGU issuer should have been seeded.');
        $this->assertSame('Digos', $row->city);
        $this->assertSame('Digos Business Permit', $row->label);
    }

    public function test_skips_when_no_stamp_region_was_detected(): void
    {
        $this->fakeEmbed();
        $this->runJob($this->document('bir_certificate', ['stamp_bbox' => null]));

        $this->assertDatabaseCount('logo_references', 0);
        Http::assertNothingSent();
    }

    public function test_skips_when_the_document_has_no_validation_result(): void
    {
        $this->fakeEmbed();
        Storage::fake('local');
        $typeId = DB::table('document_types')->where('code', 'bir_certificate')->value('id');
        $document = Document::factory()->create(['document_type_id' => $typeId]);

        $this->runJob($document);

        $this->assertDatabaseCount('logo_references', 0);
        Http::assertNothingSent();
    }

    public function test_does_not_seed_when_the_api_fails(): void
    {
        Http::fake(['*/v1/stamp/embed' => Http::response(['error' => 'boom'], 500)]);
        $document = $this->document('bir_certificate');

        $this->expectException(\RuntimeException::class);

        try {
            $this->runJob($document);
        } finally {
            // The job retries; a partial row must never be written.
            $this->assertDatabaseCount('logo_references', 0);
        }
    }
}
