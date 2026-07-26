<?php

namespace App\Services\Signature;

/**
 * The outcome of a registration signature-enrollment attempt (§5 Stage 4a).
 *
 * ``accepted`` is the gate verdict; ``reason`` is one of
 * {@see SignatureEnrollmentService}'s REASON_* codes when rejected (null when
 * accepted). On acceptance ``centroid`` is the unit-norm 128-D reference vector
 * to persist as the pipeline's signature baseline and ``samples`` the three
 * per-signature audit records.
 */
final readonly class SignatureEnrollmentResult
{
    /**
     * @param  list<array{box: list<float>, confidence: float, embedding: list<float>}>  $samples
     * @param  list<float>|null  $centroid
     * @param  array<string, mixed>  $forensics
     */
    public function __construct(
        public bool $accepted,
        public ?string $reason,
        public int $count,
        public ?float $consistency,
        public ?array $centroid,
        public array $samples,
        public array $forensics,
    ) {}
}
