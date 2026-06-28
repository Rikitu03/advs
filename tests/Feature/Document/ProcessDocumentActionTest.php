<?php

namespace Tests\Feature\Document;

use App\Actions\ProcessDocumentAction;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\ValidationResult;
use App\Services\Document\TamperDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProcessDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mock Stage T's Python call to a canned verdict; let persist() run for real.
     *
     * @param  array<string, mixed>  $verdict
     */
    private function fakeForensics(array $verdict): void
    {
        $this->mock(TamperDetectionService::class, function ($mock) use ($verdict) {
            $mock->shouldReceive('analyze')->once()->andReturn($verdict);
            $mock->shouldReceive('persist')->once()->passthru();
        });
    }

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

    public function test_pipeline_runs_stage_t_and_completes_with_low_risk(): void
    {
        $this->fakeForensics($this->cleanVerdict());
        $document = Document::factory()->create();
        ValidationResult::factory()->create(['document_id' => $document->id, 'submission_id' => $document->submission_id]);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertSame(Document::STATUS_COMPLETED, $document->fresh()->processing_status);
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
        $this->fakeForensics([
            'tamper_score' => 0.62,
            'tamper_authenticity' => 0.30,
            'tamper_confidence' => 0.90, // >= hard threshold
            'hard_flag' => true,
            'tamper_passed' => false,
            'flags' => ['Copy-move: 48 cloned keypoints'],
            'techniques' => ['copy_move' => ['score' => 0.15, 'pass' => false, 'flags' => ['Copy-move: 48 cloned keypoints']]],
        ]);

        // Every ML component is clean — only the tamper signal should drive the band.
        $document = Document::factory()->create();
        ValidationResult::factory()->create(['document_id' => $document->id, 'submission_id' => $document->submission_id]);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        $this->assertGreaterThanOrEqual(61, $result->document_risk_score);
        $this->assertContains('Document tampering suspected', $result->flags);
        $this->assertContains('Copy-move: 48 cloned keypoints', $result->flags);
        $this->assertSame('high', $document->submission->fresh()->risk_level);
    }

    public function test_missing_ml_components_raise_risk_via_penalty(): void
    {
        $this->fakeForensics($this->cleanVerdict());
        $document = Document::factory()->create();
        ValidationResult::factory()->create([
            'document_id' => $document->id,
            'submission_id' => $document->submission_id,
            'signature_detected' => false,
            'signature_score' => null,
            'stamp_detected' => false,
            'stamp_score' => null,
        ]);

        $result = app(ProcessDocumentAction::class)->execute($document->fresh());

        // Two missing components → 2 × 15 penalty points on top of the blend.
        $this->assertGreaterThanOrEqual(30, $result->document_risk_score);
    }

    public function test_job_marks_document_failed_on_failure(): void
    {
        $document = Document::factory()->create(['processing_status' => Document::STATUS_FORENSICS]);

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
