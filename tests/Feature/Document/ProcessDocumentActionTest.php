<?php

namespace Tests\Feature\Document;

use App\Actions\ProcessDocumentAction;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A document with a real file behind the 'local' disk so MlPipelineService can
     * attach it before the (faked) HTTP call.
     */
    private function document(): Document
    {
        Storage::fake('local');
        $document = Document::factory()->create();
        Storage::disk('local')->put($document->file_path, 'fake-document-bytes');

        return $document;
    }

    /**
     * Fake the ML API's /v1/validate to a canned fail-forward body.
     *
     * @param  array<string, mixed>  $stages
     * @param  list<string>  $flags
     */
    private function fakeMl(array $stages, array $flags = []): void
    {
        Http::fake([
            '*/v1/validate' => Http::response(['stages' => $stages, 'flags' => $flags], 200),
        ]);
    }

    /**
     * A clean, fully-verified /v1/validate stage set; override any stage.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cleanStages(array $overrides = []): array
    {
        return array_replace([
            'classification' => ['label' => 'BIR Permit', 'confidence' => 0.95, 'passed_threshold' => true],
            'ocr' => ['page_count' => 1, 'pages' => [[
                'text' => 'BUREAU OF INTERNAL REVENUE',
                'words' => [],
                'fields' => [],
                'quality' => [
                    'mean_confidence' => 92.0,
                    'text_validation_score' => 1.0,
                    'required_matched' => 3,
                    'required_total' => 3,
                    'flags' => [],
                ],
            ]]],
            'detection' => ['detections' => [
                ['label' => 'signature', 'confidence' => 0.9, 'box' => [10, 20, 30, 40]],
                ['label' => 'stamp', 'confidence' => 0.8, 'box' => [50, 60, 70, 80]],
            ], 'flags' => []],
            'signature' => ['match' => true, 'distance' => 0.7, 'similarity' => 0.588,
                'threshold' => 1.243976, 'embedding' => []],
            'stamp' => ['document_type' => 'bir_permit', 'city' => '', 'match' => true,
                'similarity_score' => 0.95, 'threshold' => 0.85, 'reason' => null],
            'tamper' => $this->cleanVerdict(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanVerdict(): array
    {
        return [
            'tamper_score' => 0.05,
            'tamper_authenticity' => 0.95,
            'tamper_confidence' => 0.05,
            'hard_flag' => false,
            'tamper_passed' => true,
            'flags' => [],
            'techniques' => ['metadata' => ['score' => 1.0, 'pass' => true, 'flags' => []]],
        ];
    }

    public function test_pipeline_maps_stages_and_completes_with_low_risk(): void
    {
        $document = $this->document();
        $this->fakeMl($this->cleanStages());

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertSame(Document::STATUS_COMPLETED, $document->fresh()->processing_status);

        // Component columns were mapped from the API response.
        $this->assertSame('BIR Permit', $result->classification_label);
        $this->assertEqualsWithDelta(0.95, $result->classification_confidence, 0.001);
        $this->assertTrue($result->signature_detected);
        $this->assertTrue($result->stamp_detected);
        // signature_score is the calibrated authenticity (NOT raw similarity 0.588):
        // 1 - 0.7/(2*1.243976) ≈ 0.719.
        $this->assertEqualsWithDelta(0.719, $result->signature_score, 0.005);

        $this->assertNotNull($result->document_risk_score);
        $this->assertLessThan(31, $result->document_risk_score);
        $this->assertDatabaseHas('tamper_analyses', ['document_id' => $document->id]);

        $submission = $document->submission->fresh();
        $this->assertSame('pending_review', $submission->status);
        $this->assertSame('low', $submission->risk_level);
        $this->assertEqualsWithDelta($result->document_risk_score, $submission->composite_risk_score, 0.01);
    }

    public function test_high_confidence_tamper_hard_overrides_submission_to_high(): void
    {
        $document = $this->document();
        // Every ML component is clean — only the tamper signal should drive the band.
        $this->fakeMl($this->cleanStages(['tamper' => [
            'tamper_score' => 0.62,
            'tamper_authenticity' => 0.30,
            'tamper_confidence' => 0.90, // >= hard threshold
            'hard_flag' => true,
            'tamper_passed' => false,
            'flags' => ['Copy-move: 48 cloned keypoints'],
            'techniques' => ['copy_move' => ['score' => 0.15, 'pass' => false, 'flags' => ['Copy-move: 48 cloned keypoints']]],
        ]]));

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertGreaterThanOrEqual(61, $result->document_risk_score);
        $this->assertContains('Document tampering suspected', $result->flags);
        $this->assertContains('Copy-move: 48 cloned keypoints', $result->flags);
        $this->assertSame('high', $document->submission->fresh()->risk_level);
    }

    public function test_skipped_ml_components_raise_risk_via_penalty(): void
    {
        $document = $this->document();
        $this->fakeMl($this->cleanStages([
            'signature' => ['skipped' => true, 'reason' => 'no_signature_detected'],
            'stamp' => ['skipped' => true, 'reason' => 'no_stamp_detected'],
        ]));

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        // Two missing components → 2 × 15 penalty points on top of the blend.
        $this->assertFalse($result->signature_detected);
        $this->assertFalse($result->stamp_detected);
        $this->assertGreaterThanOrEqual(30, $result->document_risk_score);
    }

    public function test_ml_api_failure_fails_forward_and_still_finalizes(): void
    {
        $document = $this->document();
        Http::fake(['*/v1/validate' => Http::response('', 500)]);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertSame(Document::STATUS_COMPLETED, $document->fresh()->processing_status);
        $this->assertContains('ML pipeline unavailable', $result->flags);
        // No component scores + no forensic verdict → nothing persisted for Stage T.
        $this->assertDatabaseMissing('tamper_analyses', ['document_id' => $document->id]);
        $this->assertNotNull($result->document_risk_score);
    }

    /**
     * Flags describe the CURRENT pipeline run. Re-running a document whose
     * earlier run failed (ML API down, Stage 2 timed out) must clear that
     * run's flags — otherwise "ML pipeline unavailable" and "Text validation
     * unavailable" stay on the officer's drill-down forever, describing a
     * failure that has since been fixed.
     */
    public function test_reprocessing_clears_the_previous_runs_flags(): void
    {
        $document = $this->document();
        // One attempt per execute(), so the sequence below maps 1:1 onto runs.
        config(['advs.ml.retries' => 1]);
        // A sequence, not two fake() calls — fake() MERGES stubs and the first
        // match wins, so a second fake() of the same URL would never take effect.
        Http::fakeSequence('*/v1/validate')
            ->push('', 500)
            ->push(['stages' => $this->cleanStages(), 'flags' => []], 200);

        $failed = app(ProcessDocumentAction::class)->execute($document->fresh());
        $this->assertContains('ML pipeline unavailable', $failed->flags);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertNotContains('ML pipeline unavailable', $result->flags);
        $this->assertNotContains('Text validation unavailable', $result->flags);
        $this->assertSame(1.0, (float) $result->text_validation_score);
    }

    public function test_job_marks_document_failed_on_failure(): void
    {
        $document = Document::factory()->create(['processing_status' => Document::STATUS_VERIFYING]);

        (new ProcessDocumentJob($document))->failed(new \RuntimeException('boom'));

        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->processing_status);
    }

    public function test_job_is_queued_on_the_document_processing_queue(): void
    {
        Bus::fake();
        $document = Document::factory()->create();

        ProcessDocumentJob::dispatch($document);

        Bus::assertDispatched(ProcessDocumentJob::class, function (ProcessDocumentJob $job) {
            return $job->queue === 'document-processing'
                && $job->tries === 3
                && $job->timeout === 300;
        });
    }
}
