<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\Submission;
use App\Models\ValidationResult;
use App\Services\Document\RiskScoreService;
use App\Services\Document\TamperDetectionService;

/**
 * Single orchestrator for the document-validation pipeline (ADVS_System_Reference.md
 * §5). It runs the stages in order and assembles the per-document result.
 *
 * Stages 1–4b (preprocess → OCR → classify → signature/stamp) populate the
 * document's {@see ValidationResult} with component authenticity scores; those
 * services are wired in here as they are built (they require trained models that
 * do not yet exist). Stage T (forensic tampering) IS built and runs here on the
 * ORIGINAL upload, and its verdict joins the four ML component scores in the
 * 5-term composite risk (Stage 5), with a high-confidence tamper signal able to
 * hard-override the band to High.
 */
class ProcessDocumentAction
{
    public function __construct(
        private readonly TamperDetectionService $tamperService,
        private readonly RiskScoreService $riskService,
    ) {}

    /**
     * Run the pipeline for one document: Stage T → risk aggregation → finalize.
     *
     * @param  array<string, mixed>|null  $stageContext  Optional OCR text/words/fields/issue_date for Stage T.
     */
    public function execute(Document $document, ?array $stageContext = null): ValidationResult
    {
        // ── Stages 1–4b ────────────────────────────────────────────────────
        // Preprocess / OCR / classify / detect / verify populate $result. Until
        // those Python wrappers land, we consume whatever upstream produced (or
        // an empty result, which scores every ML component as missing).
        $result = $document->validationResult()->firstOrNew([], [
            'submission_id' => $document->submission_id,
        ]);

        // ── Stage T: forensic tampering (on the ORIGINAL upload) ────────────
        $document->update(['processing_status' => Document::STATUS_FORENSICS]);

        $payload = $stageContext === null
            ? null
            : $this->tamperService->buildPayload(
                $document,
                ocrText: $stageContext['ocr_text'] ?? $result->ocr_extracted_text,
                ocrWords: $stageContext['ocr_words'] ?? [],
                fields: $stageContext['fields'] ?? [],
                issueDate: $stageContext['issue_date'] ?? null,
            );

        $verdict = $this->tamperService->analyze($document, $payload);
        $this->tamperService->persist($document, $verdict);

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
            $verdict['flags'] ?? [],
            $risk['hard_override'] ? ['Document tampering suspected'] : [],
        )));

        $result->fill([
            'submission_id' => $document->submission_id,
            'document_risk_score' => $risk['score'],
            'flags' => $flags,
        ]);
        $document->validationResult()->save($result);

        $document->update(['processing_status' => Document::STATUS_COMPLETED]);

        $this->rollUpSubmission($document->submission);

        return $result;
    }

    /**
     * Roll the submission's composite risk up from its documents (highest-risk
     * document drives the review queue) and move it to PENDING_REVIEW.
     */
    private function rollUpSubmission(Submission $submission): void
    {
        $max = (float) $submission->documents()
            ->join('validation_results', 'validation_results.document_id', '=', 'documents.id')
            ->max('validation_results.document_risk_score');

        $high = (int) config('advs.risk.high_threshold');
        $medium = (int) config('advs.risk.medium_threshold');

        $submission->update([
            'composite_risk_score' => $max,
            'risk_level' => match (true) {
                $max >= $high => 'high',
                $max >= $medium => 'medium',
                default => 'low',
            },
            'status' => Submission::STATUS_PENDING_REVIEW,
        ]);
    }
}
