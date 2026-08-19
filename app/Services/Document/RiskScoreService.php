<?php

namespace App\Services\Document;

use App\Services\SystemSettingsService;

/**
 * Computes a document's composite risk score (ADVS_System_Reference.md §5
 * Stage 5 / §6) from the pipeline's component authenticity scores.
 *
 *   Composite Risk = Σ wN * (1 - componentN) * 100 + penalty_flags
 *
 * The five weighted components are text validation, classification, signature,
 * stamp, and the Stage T forensic tampering authenticity. Each component is an
 * authenticity in [0,1] (higher = cleaner); a missing component is excluded from
 * the (renormalised) weighted blend and instead contributes a fixed penalty.
 *
 * A `tamper_confidence` at/above the configured hard threshold HARD-OVERRIDES
 * the result to High Risk regardless of the blend — strong, localized fraud
 * (e.g. an exact-pixel clone) must not be averaged away by the clean stages.
 */
class RiskScoreService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    /**
     * @param array{
     *     text?: float|null,
     *     classification?: float|null,
     *     signature?: float|null,
     *     stamp?: float|null,
     *     tamper_authenticity?: float|null,
     *     tamper_confidence?: float|null,
     *     penalty_points?: int|float,
     * } $components
     * @return array{score: float, level: string, hard_override: bool, base: float, penalties: float, breakdown: array<string, mixed>}
     */
    public function compute(array $components, ?array $snapshot = null): array
    {
        $snapshot ??= $this->settings->pipelineSnapshot();
        $weights = [
            'text' => (float) ($snapshot['RISK_WEIGHT_TEXT'] ?? 0.20),
            'classification' => (float) ($snapshot['RISK_WEIGHT_CLASSIFICATION'] ?? 0.20),
            'signature' => (float) ($snapshot['RISK_WEIGHT_SIGNATURE'] ?? 0.20),
            'stamp' => (float) ($snapshot['RISK_WEIGHT_STAMP'] ?? 0.20),
            'tamper' => (float) ($snapshot['RISK_WEIGHT_TAMPER'] ?? 0.20),
        ];
        $missingPenalty = (float) ($snapshot['MISSING_COMPONENT_PENALTY'] ?? 15);
        $highThreshold = (int) ($snapshot['HIGH_RISK_THRESHOLD'] ?? 61);
        $mediumThreshold = (int) ($snapshot['MEDIUM_RISK_THRESHOLD'] ?? 31);
        $hardThreshold = (float) ($snapshot['TAMPER_HARD_THRESHOLD'] ?? 0.80);

        // Map each weighted term to its source authenticity score.
        $scores = [
            'text' => $components['text'] ?? null,
            'classification' => $components['classification'] ?? null,
            'signature' => $components['signature'] ?? null,
            'stamp' => $components['stamp'] ?? null,
            'tamper' => $components['tamper_authenticity'] ?? null,
        ];

        $weightedSum = 0.0;
        $presentWeight = 0.0;
        $penalties = (float) ($components['penalty_points'] ?? 0);
        $breakdown = [];

        foreach ($scores as $name => $score) {
            $weight = (float) ($weights[$name] ?? 0);
            if ($score === null) {
                // Forensics is fail-forward (no penalty when skipped); the four
                // ML components each cost the missing-component penalty.
                if ($name !== 'tamper') {
                    $penalties += $missingPenalty;
                    $breakdown[$name] = ['score' => null, 'weight' => $weight, 'missing' => true];
                }

                continue;
            }

            $score = max(0.0, min(1.0, (float) $score));
            $contribution = $weight * (1.0 - $score);
            $weightedSum += $contribution;
            $presentWeight += $weight;
            $breakdown[$name] = ['score' => $score, 'weight' => $weight, 'risk_contribution' => round($contribution, 4)];
        }

        $base = $presentWeight > 0 ? ($weightedSum / $presentWeight) * 100.0 : 0.0;
        $score = max(0.0, min(100.0, $base + $penalties));

        $level = match (true) {
            $score >= $highThreshold => 'high',
            $score >= $mediumThreshold => 'medium',
            default => 'low',
        };

        // Hard override: a high-confidence tamper signal forces High Risk.
        $tamperConfidence = (float) ($components['tamper_confidence'] ?? 0);
        $hardOverride = $tamperConfidence >= $hardThreshold;
        if ($hardOverride) {
            $level = 'high';
            $score = max($score, (float) $highThreshold);
        }

        return [
            'score' => round($score, 2),
            'level' => $level,
            'hard_override' => $hardOverride,
            'base' => round($base, 2),
            'penalties' => round($penalties, 2),
            'breakdown' => $breakdown,
        ];
    }
}
