<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\PipelineRun;
use App\Models\ValidationResult;
use App\Services\Document\MlPipelineService;
use App\Services\Document\RiskScoreService;
use App\Services\Document\SubmissionFinalizer;
use App\Services\Document\TamperDetectionService;
use App\Services\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates one document attempt against the FastAPI validation endpoint.
 *
 * Inference runs without an open database transaction. A valid response is
 * persisted atomically; transport and malformed-response failures are recorded
 * as append-only run attempts and rethrown so the queue can retry.
 */
class ProcessDocumentAction
{
    public function __construct(
        private readonly MlPipelineService $mlPipeline,
        private readonly TamperDetectionService $tamperService,
        private readonly RiskScoreService $riskService,
        private readonly SubmissionFinalizer $finalizer,
        private readonly SystemSettingsService $settings,
    ) {}

    public function execute(Document $document, int $attempt = 1): ValidationResult
    {
        $startedAt = now();
        $settingsSnapshot = $this->settings->pipelineSnapshot();

        $document->update(['processing_status' => Document::STATUS_VERIFYING]);

        try {
            $response = $this->mlPipeline->validate($document, $settingsSnapshot);
        } catch (Throwable $error) {
            $this->recordFailedRun($document, $attempt, $startedAt, $settingsSnapshot, $error);

            throw $error;
        }

        $mapped = $this->mlPipeline->mapStages($response['stages'], $response['context']);
        $result = $document->validationResult()->firstOrNew([], [
            'submission_id' => $document->submission_id,
        ]);
        $result->fill($mapped['columns']);

        $verdict = [];
        $tamperStage = $response['stages']['tamper'] ?? null;
        if (is_array($tamperStage) && ($tamperStage['skipped'] ?? false) !== true) {
            $verdict = $tamperStage;
        }

        $risk = $this->riskService->compute([
            'text' => $result->text_validation_score,
            'classification' => $result->classification_authenticity ?? $result->classification_confidence,
            'signature' => $result->signature_detected ? $result->signature_score : null,
            'stamp' => $result->stamp_detected ? $result->stamp_score : null,
            'tamper_authenticity' => $verdict['tamper_authenticity'] ?? null,
            'tamper_confidence' => $verdict['tamper_confidence'] ?? null,
        ], $settingsSnapshot);

        $flags = array_values(array_unique(array_merge(
            $response['flags'],
            $mapped['flags'],
            $verdict['flags'] ?? [],
            $risk['hard_override'] ? ['Document tampering suspected'] : [],
            $this->missingStageFlags($risk['breakdown']),
        )));

        $result->fill([
            'submission_id' => $document->submission_id,
            'document_risk_score' => $risk['score'],
            'flags' => $flags,
        ]);

        DB::transaction(function () use (
            $document,
            $attempt,
            $startedAt,
            $settingsSnapshot,
            $response,
            $result,
            $verdict,
            $flags,
        ): void {
            $document->validationResult()->save($result);

            if ($verdict !== []) {
                $this->tamperService->persist($document, $verdict);
            }

            $run = PipelineRun::query()->create([
                'document_id' => $document->id,
                'submission_id' => $document->submission_id,
                'attempt' => max(1, $attempt),
                'status' => PipelineRun::STATUS_COMPLETED,
                'schema_version' => $response['schema_version'],
                'settings_snapshot' => $settingsSnapshot,
                'settings_hash' => $response['settings_hash']
                    ?? $this->settings->pipelineSnapshotHash($settingsSnapshot),
                'models' => $response['models'],
                'timings' => $response['timings'],
                'flags' => $flags,
                'error' => null,
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);

            foreach ($response['pages'] as $index => $page) {
                if (! is_array($page)) {
                    continue;
                }

                $run->pages()->create([
                    'document_id' => $document->id,
                    'submission_id' => $document->submission_id,
                    'page_index' => max(1, (int) ($page['page_index'] ?? ($index + 1))),
                    'status' => in_array($page['status'] ?? null, ['completed', 'skipped', 'failed'], true)
                        ? $page['status']
                        : 'completed',
                    'stages' => is_array($page['stages'] ?? null) ? $page['stages'] : [],
                    'flags' => is_array($page['flags'] ?? null) ? array_values($page['flags']) : [],
                    'timings' => is_array($page['timings'] ?? null) ? $page['timings'] : [],
                ]);
            }

            $document->update(['processing_status' => Document::STATUS_COMPLETED]);
        });

        $this->finalizer->finalize($document->submission);

        return $result;
    }

    /**
     * @param  array<string, string|int|float|bool>  $settingsSnapshot
     */
    private function recordFailedRun(
        Document $document,
        int $attempt,
        Carbon $startedAt,
        array $settingsSnapshot,
        Throwable $error,
    ): void {
        PipelineRun::query()->create([
            'document_id' => $document->id,
            'submission_id' => $document->submission_id,
            'attempt' => max(1, $attempt),
            'status' => PipelineRun::STATUS_FAILED,
            'schema_version' => null,
            'settings_snapshot' => $settingsSnapshot,
            'settings_hash' => $this->settings->pipelineSnapshotHash($settingsSnapshot),
            'models' => null,
            'timings' => null,
            'flags' => ['pipeline_request_failed'],
            'error' => [
                'type' => $error::class,
                'message' => mb_substr($error->getMessage(), 0, 1000),
            ],
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $breakdown
     * @return list<string>
     */
    private function missingStageFlags(array $breakdown): array
    {
        $labels = [
            'text' => 'Text validation unavailable',
            'classification' => 'Classification unavailable',
            'signature' => 'Signature verification unavailable',
            'stamp' => 'Stamp verification unavailable',
        ];

        $flags = [];

        foreach ($labels as $component => $label) {
            if (($breakdown[$component]['missing'] ?? false) === true) {
                $flags[] = $label;
            }
        }

        return $flags;
    }
}
