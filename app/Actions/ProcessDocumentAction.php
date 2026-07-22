<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\ValidationResult;
use App\Services\Document\MlPipelineService;
use App\Services\Document\RiskScoreService;
use App\Services\Document\SubmissionFinalizer;
use App\Services\Document\TamperDetectionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single orchestrator for the document-validation pipeline (ADVS_System_Reference.md
 * §5). It runs the full pipeline in ONE call to the FastAPI ML service
 * ({@see MlPipelineService} → POST /v1/validate), maps the per-stage result onto
 * the document's {@see ValidationResult}, and assembles the 5-term composite risk.
 *
 * The API returns classify / OCR / detect / signature / stamp component scores and
 * the Stage-T forensic verdict in one fail-forward response; {@see RiskScoreService}
 * (Stage 5) blends them, with a high-confidence tamper signal able to hard-override
 * the band to High. If the ML service is unreachable the pipeline fails forward —
 * every ML component scores as missing and the document still finalizes — matching
 * the API's own fail-forward contract.
 */
class ProcessDocumentAction
{
    public function __construct(
        private readonly MlPipelineService $mlPipeline,
        private readonly TamperDetectionService $tamperService,
        private readonly RiskScoreService $riskService,
        private readonly SubmissionFinalizer $finalizer,
    ) {}

    /**
     * Run the pipeline for one document: ML API → map → Stage T persist → risk → finalize.
     */
    public function execute(Document $document): ValidationResult
    {
        $result = $document->validationResult()->firstOrNew([], [
            'submission_id' => $document->submission_id,
        ]);

        $document->update(['processing_status' => Document::STATUS_VERIFYING]);

        // ── Stages 2–4b + Stage T in one call (fail-forward) ────────────────
        $mlFlags = [];
        $verdict = [];

        try {
            $response = $this->mlPipeline->validate($document);

            $mapped = $this->mlPipeline->mapStages($response['stages'], $response['context']);
            $result->fill($mapped['columns']);
            $mlFlags = array_merge($response['flags'], $mapped['flags']);

            // Stage T forensics ran inside /v1/validate — persist its verdict.
            $tamperStage = $response['stages']['tamper'] ?? null;
            if (is_array($tamperStage) && ($tamperStage['skipped'] ?? false) !== true) {
                $verdict = $tamperStage;
                $this->tamperService->persist($document, $verdict);
            }
        } catch (Throwable $e) {
            // Fail-forward: score with missing components, still finalize. The
            // queued job retries transient outages ($tries=3) before this path.
            Log::warning('ML pipeline unavailable; scoring with missing components', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            $mlFlags[] = 'ML pipeline unavailable';
        }

        // ── Stage 5: composite risk (4 ML components + forensic authenticity) ─
        $risk = $this->riskService->compute([
            'text' => $result->text_validation_score,
            'classification' => $result->classification_confidence,
            'signature' => $result->signature_detected ? $result->signature_score : null,
            'stamp' => $result->stamp_detected ? $result->stamp_score : null,
            'tamper_authenticity' => $verdict['tamper_authenticity'] ?? null,
            'tamper_confidence' => $verdict['tamper_confidence'] ?? null,
        ]);

        $flags = array_values(array_unique(array_merge(
            $result->flags ?? [],
            $mlFlags,
            $verdict['flags'] ?? [],
            $risk['hard_override'] ? ['Document tampering suspected'] : [],
            $this->missingStageFlags($risk['breakdown']),
        )));

        $result->fill([
            'submission_id' => $document->submission_id,
            'document_risk_score' => $risk['score'],
            'flags' => $flags,
        ]);
        $document->validationResult()->save($result);

        $document->update(['processing_status' => Document::STATUS_COMPLETED]);

        $this->finalizer->finalize($document->submission);

        return $result;
    }

    /**
     * Human-readable flags for the ML components the risk blend scored as
     * missing — in standby mode these are the stages whose models are not yet
     * wired in, so the drill-down states plainly why the penalty was applied.
     *
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
