<?php

namespace Tests\Feature\Document;

use App\Actions\ProcessDocumentAction;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\Document\MlApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
        $document->submission->vendor->update([
            'company_name' => 'BUREAU OF INTERNAL REVENUE',
            'trade_name' => null,
            'tin' => null,
            'dti_registration_number' => null,
            'sec_registration_number' => null,
            'business_permit_number' => null,
            'registration_number' => null,
            'business_street' => null,
            'business_barangay' => null,
            'business_city' => null,
            'business_province' => null,
            'business_postal_code' => null,
        ]);
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
            '*/v1/validate' => Http::response($this->mlResponse($stages, $flags), 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stages
     * @param  list<string>  $flags
     * @return array<string, mixed>
     */
    private function mlResponse(array $stages, array $flags = []): array
    {
        return [
            'schema_version' => '1.0',
            'status' => 'completed',
            'stages' => $stages,
            'flags' => $flags,
            'pages' => [[
                'page_index' => 1,
                'status' => 'completed',
                'stages' => $stages,
                'flags' => $flags,
                'timings' => ['total_ms' => 12],
            ]],
            'models' => ['classifier' => ['loaded' => true, 'version' => 'test']],
            'settings_hash' => str_repeat('a', 64),
            'timings' => ['total_ms' => 12],
        ];
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
        $this->assertDatabaseHas('pipeline_runs', ['document_id' => $document->id, 'status' => 'completed']);
        $this->assertDatabaseHas('pipeline_page_results', ['document_id' => $document->id, 'page_index' => 1]);

        $submission = $document->submission->fresh();
        $this->assertSame('pending_review', $submission->status);
        $this->assertSame('low', $submission->risk_level);
        $this->assertEqualsWithDelta($result->document_risk_score, $submission->composite_risk_score, 0.01);
    }

    public function test_vendor_registration_matching_replaces_the_ml_text_score_before_risk_is_computed(): void
    {
        $document = $this->document();
        $document->submission->vendor->update([
            'company_name' => 'Acme Foods, Inc.',
            'trade_name' => 'Acme Kitchen',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026-001',
            'sec_registration_number' => null,
            'business_permit_number' => 'BP-26-99',
            'registration_number' => null,
        ]);

        $stages = $this->cleanStages([
            'ocr' => ['page_count' => 1, 'pages' => [[
                'text' => 'ACME FOODS INC ACME KITCHEN TIN 123456789000 DTI 2026 001 BP 26 99',
                'words' => [],
                'fields' => [],
                'quality' => [
                    'mean_confidence' => 92.0,
                    'text_validation_score' => 0.01,
                    'required_matched' => 0,
                    'required_total' => 99,
                    'flags' => [],
                ],
            ]]],
        ]);
        $this->fakeMl($stages);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertEqualsWithDelta(1.0, $result->text_validation_score, 1e-6);
        $this->assertSame(5, $result->text_fields_matched);
        $this->assertSame(5, $result->text_fields_expected);
        $this->assertLessThan(31, $result->document_risk_score);
    }

    public function test_unavailable_ocr_does_not_fall_back_to_a_previous_or_ml_text_score(): void
    {
        $document = $this->document();
        $document->validationResult()->create([
            'submission_id' => $document->submission_id,
            'ocr_extracted_text' => 'BUREAU OF INTERNAL REVENUE',
            'text_validation_score' => 1.0,
            'text_fields_matched' => 1,
            'text_fields_expected' => 1,
        ]);
        $this->fakeMl($this->cleanStages([
            'ocr' => ['skipped' => true, 'reason' => 'insufficient_text'],
        ]));

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertNull($result->ocr_extracted_text);
        $this->assertNull($result->text_validation_score);
        $this->assertSame(0, $result->text_fields_matched);
        $this->assertSame(1, $result->text_fields_expected);
        $this->assertContains('Text validation unavailable', $result->flags);
    }

    public function test_a_document_the_classifier_calls_fake_outranks_a_genuine_one_on_risk(): void
    {
        $genuine = $this->document();
        // A sequence, not two fakeMl() calls — fake() MERGES stubs and the first
        // match wins, so a second fake() of the same URL would never take effect.
        Http::fakeSequence('*/v1/validate')
            ->push(['stages' => $this->cleanStages(), 'flags' => []], 200)
            ->push(['stages' => $this->cleanStages(['classification' => [
                'label' => 'fake', 'confidence' => 0.98, 'authenticity' => 0.02,
                'passed_threshold' => true,
            ]]), 'flags' => []], 200);

        $clean = app(ProcessDocumentAction::class)->execute($genuine->fresh());

        $suspect = $this->document();
        $result = app(ProcessDocumentAction::class)->execute($suspect->fresh());

        // Both runs are equally CONFIDENT (0.95 vs 0.98) — only authenticity
        // moved. Before this change the forgery scored LOWER risk than the
        // genuine document, because confidence was feeding the blend.
        $this->assertEqualsWithDelta(0.02, $result->classification_authenticity, 1e-6);
        // 0.20 weight × (0.98 - 0.05) ≈ 18.6 points of separation.
        $this->assertGreaterThan($clean->document_risk_score + 15, $result->document_risk_score);
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

    public function test_ml_api_failure_is_recorded_and_rethrown_for_queue_retry(): void
    {
        $document = $this->document();
        Http::fake(['*/v1/validate' => Http::response('', 500)]);

        try {
            app(ProcessDocumentAction::class)->execute($document->fresh(), 2);
            $this->fail('The HTTP failure should be rethrown.');
        } catch (RuntimeException) {
        }

        $this->assertSame(Document::STATUS_VERIFYING, $document->fresh()->processing_status);
        $this->assertDatabaseHas('pipeline_runs', ['document_id' => $document->id, 'attempt' => 2, 'status' => 'failed']);
        // No component scores + no forensic verdict → nothing persisted for Stage T.
        $this->assertDatabaseMissing('tamper_analyses', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('validation_results', ['document_id' => $document->id]);
    }

    public function test_ml_api_retries_a_transient_service_failure(): void
    {
        $document = $this->document();
        config(['advs.ml.retries' => 1]);
        Http::preventStrayRequests();
        Http::fakeSequence('*/v1/validate')
            ->push(['detail' => 'models are warming'], 503)
            ->push($this->mlResponse($this->cleanStages()), 200);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertSame(Document::STATUS_COMPLETED, $document->fresh()->processing_status);
        $this->assertSame('BIR Permit', $result->classification_label);
        Http::assertSentCount(2);
    }

    public function test_ml_api_does_not_retry_a_contract_failure_and_preserves_details(): void
    {
        $document = $this->document();
        config(['advs.ml.retries' => 2]);
        Http::preventStrayRequests();
        Http::fakeSequence('*/v1/validate')
            ->push(['detail' => [['loc' => ['body', 'file'], 'msg' => 'Field required']]], 422)
            ->push($this->mlResponse($this->cleanStages()), 200);

        try {
            app(ProcessDocumentAction::class)->execute($document->fresh());
            $this->fail('The contract failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 422', $exception->getMessage());
            $this->assertStringContainsString('Field required', $exception->getMessage());
            $this->assertStringContainsString("document {$document->id}", $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseHas('pipeline_runs', [
            'document_id' => $document->id,
            'status' => 'failed',
        ]);
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
            ->push(['stages' => $this->cleanStages(), 'flags' => ['transient_stage_flag']], 200)
            ->push(['stages' => $this->cleanStages(), 'flags' => []], 200);

        $failed = app(ProcessDocumentAction::class)->execute($document->fresh());
        $this->assertContains('transient_stage_flag', $failed->flags);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertNotContains('transient_stage_flag', $result->flags);
        $this->assertNotContains('Text validation unavailable', $result->flags);
        $this->assertSame(1.0, (float) $result->text_validation_score);
        $this->assertSame(1, $result->text_fields_matched);
        $this->assertSame(1, $result->text_fields_expected);
    }

    public function test_job_marks_document_failed_on_failure(): void
    {
        $document = Document::factory()->create(['processing_status' => Document::STATUS_VERIFYING]);

        (new ProcessDocumentJob($document))->failed(new RuntimeException('boom'));

        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->processing_status);
        $this->assertContains('processing_failed', $document->validationResult->flags);
    }

    public function test_job_fails_terminal_ml_contract_errors_without_queue_retry(): void
    {
        $document = $this->document();
        config(['advs.ml.retries' => 2]);
        Http::preventStrayRequests();
        Http::fake([
            '*/v1/validate' => Http::response(['detail' => 'invalid multipart contract'], 422),
        ]);

        (new ProcessDocumentJob($document))->handle(app(ProcessDocumentAction::class));

        Http::assertSentCount(1);
        $this->assertSame(Document::STATUS_FAILED, $document->fresh()->processing_status);
        $this->assertContains('processing_failed', $document->validationResult->flags);
    }

    public function test_job_rethrows_retryable_ml_service_errors_for_queue_retry(): void
    {
        $document = $this->document();
        config(['advs.ml.retries' => 0]);
        Http::preventStrayRequests();
        Http::fake([
            '*/v1/validate' => Http::response(['detail' => 'service unavailable'], 503),
        ]);

        try {
            (new ProcessDocumentJob($document))->handle(app(ProcessDocumentAction::class));
            $this->fail('The retryable ML failure should be rethrown.');
        } catch (MlApiException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $this->assertSame(Document::STATUS_VERIFYING, $document->fresh()->processing_status);
        $this->assertDatabaseHas('pipeline_runs', [
            'document_id' => $document->id,
            'attempt' => 1,
            'status' => 'failed',
        ]);
    }

    public function test_job_is_queued_on_the_document_processing_queue(): void
    {
        Queue::fake();
        $document = Document::factory()->create();

        ProcessDocumentJob::dispatch($document);

        Queue::assertPushed(ProcessDocumentJob::class, function (ProcessDocumentJob $job) use ($document) {
            return $job->queue === 'document-processing'
                && $job->tries === 3
                && $job->timeout === 360
                && $job->backoff === [10, 30, 60]
                && $job->uniqueId() === (string) $document->id;
        });

        $this->assertGreaterThan(
            360,
            (int) config('queue.connections.database.retry_after'),
            'The database queue retry_after must exceed the document job timeout.',
        );
    }

    /**
     * Regression: handle() used to call the non-existent Document::freshOrFail(),
     * which threw immediately and exhausted every job into failed_jobs before the
     * pipeline ever ran. The job must reload a fresh model and process to completion.
     */
    public function test_job_handle_processes_document_to_completion(): void
    {
        $document = $this->document();
        $this->fakeMl($this->cleanStages());

        (new ProcessDocumentJob($document))->handle(app(ProcessDocumentAction::class));

        $this->assertSame(Document::STATUS_COMPLETED, $document->fresh()->processing_status);
        $this->assertDatabaseHas('validation_results', ['document_id' => $document->id]);
        $this->assertDatabaseHas('pipeline_runs', ['document_id' => $document->id, 'status' => 'completed']);
    }

    /**
     * When the document is deleted between dispatch and handle(), the job must
     * throw rather than silently skip or pass null to execute().
     */
    public function test_job_handle_throws_when_document_no_longer_exists(): void
    {
        $document = Document::factory()->create(['processing_status' => Document::STATUS_QUEUED]);
        $docId = $document->id;
        $document->delete();

        $job = new ProcessDocumentJob($document);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Document #{$docId} no longer exists.");

        $job->handle(app(ProcessDocumentAction::class));
    }
}
