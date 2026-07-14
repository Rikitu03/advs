<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Risk score composition (ADVS_System_Reference.md §5 Stage 5 / §6 / §9)
    |--------------------------------------------------------------------------
    |
    | Composite Risk = Σ wN * (1 - componentN_score) * 100 + penalty_flags,
    | with the five weights summing to 1.0. Each component score is an
    | authenticity in [0,1] (higher = cleaner), so a low score raises risk.
    | A high-confidence tampering signal hard-overrides the band to High.
    */

    'risk' => [
        'weights' => [
            'text' => (float) env('RISK_WEIGHT_TEXT', 0.20),
            'classification' => (float) env('RISK_WEIGHT_CLASSIFICATION', 0.20),
            'signature' => (float) env('RISK_WEIGHT_SIGNATURE', 0.20),
            'stamp' => (float) env('RISK_WEIGHT_STAMP', 0.20),
            'tamper' => (float) env('RISK_WEIGHT_TAMPER', 0.20),
        ],

        // Fixed risk points added when a pipeline component is missing entirely
        // (e.g. no signature/stamp detected, insufficient OCR text).
        'missing_component_penalty' => (int) env('MISSING_COMPONENT_PENALTY', 15),

        // Risk-band cutoffs on the 0-100 composite score.
        'high_threshold' => (int) env('HIGH_RISK_THRESHOLD', 61),
        'medium_threshold' => (int) env('MEDIUM_RISK_THRESHOLD', 31),

        // tamper_confidence at/above this forces the submission to High Risk
        // regardless of the weighted blend (strong, localized fraud).
        'tamper_hard_threshold' => (float) env('TAMPER_HARD_THRESHOLD', 0.80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-component pass thresholds (§9) — authenticity scores in [0,1]
    |--------------------------------------------------------------------------
    |
    | Displayed on the risk drill-down and used to mark a component pass/fail.
    | SIGNATURE_DISTANCE_THRESHOLD is empirical (§9); the similarity threshold
    | here is its presentation-side equivalent.
    */

    'thresholds' => [
        'text' => (float) env('TEXT_VALIDATION_THRESHOLD', 0.70),
        'classification' => (float) env('CLASSIFICATION_CONFIDENCE_THRESHOLD', 0.70),
        'signature' => (float) env('SIGNATURE_SIMILARITY_THRESHOLD', 0.75),
        'stamp' => (float) env('STAMP_SIMILARITY_THRESHOLD', 0.85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage T — forensic tampering analysis (python/scripts/tamper_analyze.py)
    |--------------------------------------------------------------------------
    */

    'forensics' => [
        // Aggregate authenticity below this fails the forensic gate.
        'tamper_authenticity_threshold' => (float) env('TAMPER_AUTHENTICITY_THRESHOLD', 0.50),

        // A single technique's tamper signal at/above this hard-flags the doc.
        'hard_confidence' => (float) env('TAMPER_HARD_THRESHOLD', 0.80),

        // Per-technique blend weights for the aggregate tamper score. Mirrors
        // python/forensics.DEFAULT_WEIGHTS; passed through to the script.
        'weights' => [
            'metadata' => (float) env('TAMPER_WEIGHT_METADATA', 0.20),
            'ela' => (float) env('TAMPER_WEIGHT_ELA', 0.25),
            'copy_move' => (float) env('TAMPER_WEIGHT_COPY_MOVE', 0.25),
            'font' => (float) env('TAMPER_WEIGHT_FONT', 0.15),
            'cross_reference' => (float) env('TAMPER_WEIGHT_CROSS_REFERENCE', 0.15),
        ],

        // Python invocation (Process facade) — mirrors the other Stage runners.
        'python_bin' => env('ADVS_PYTHON_BIN', 'python3'),
        'script' => 'scripts/tamper_analyze.py',
        'timeout' => (int) env('TAMPER_TIMEOUT', 120),
    ],

];
