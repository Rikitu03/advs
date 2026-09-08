<?php

namespace App\Services\Signature;

use App\Services\Document\MlPipelineService;
use App\Services\SystemSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Registration-time signature enrollment against the FastAPI ML service
 * (POST {advs.ml.base_url}{advs.signature.enroll_endpoint}).
 *
 * The endpoint returns raw measurements (detected signature count, per-signature
 * embeddings, the mean pairwise consistency, a unit-norm centroid, and a lenient
 * edited/pasted-on-top forensics summary); this service owns the accept/reject
 * policy — exactly {@see config()} `advs.signature.expected_count` signatures,
 * consistency at/above `consistency_threshold`, and no forensic hard-flag —
 * mirroring how {@see MlPipelineService} leaves risk scoring
 * to its own orchestrator. Transport happens over the shared `advs.ml` client.
 */
class SignatureEnrollmentService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public const REASON_COUNT = 'expected_signature_count';

    public const REASON_SIMILARITY = 'signatures_not_similar';

    public const REASON_EDITED = 'image_edited';

    /**
     * Detect, embed, and gate the three-signature enrollment photo.
     *
     * @throws RuntimeException on any transport or non-2xx failure (the caller
     *                          surfaces a generic "try again" message).
     */
    public function enroll(string $contents, string $filename): SignatureEnrollmentResult
    {
        $ml = config('advs.ml');
        $signature = config('advs.signature');
        $endpoint = (string) $signature['enroll_endpoint'];

        try {
            $response = Http::baseUrl($ml['base_url'])
                ->withToken((string) $ml['token'])
                ->connectTimeout((int) ($ml['connect_timeout'] ?? 10))
                ->timeout((int) ($ml['timeout'] ?? 180))
                ->retry(max(1, (int) ($ml['retries'] ?? 1)), 200, throw: false)
                ->attach('file', $contents, $filename)
                ->post($endpoint, [
                    'signature_enroll_detection_confidence' => (float) ($this->settings->pipelineSnapshot()['SIGNATURE_ENROLL_DETECTION_CONFIDENCE'] ?? 0.20),
                ]);
        } catch (ConnectionException $exc) {
            throw new RuntimeException("ML API unreachable at {$ml['base_url']}: {$exc->getMessage()}", previous: $exc);
        }

        if ($response->failed()) {
            throw new RuntimeException("ML API {$endpoint} returned HTTP {$response->status()}.");
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('count', $body)) {
            throw new RuntimeException('ML API signature enrollment returned an unexpected body.');
        }

        return $this->evaluate(
            $body,
            (int) $signature['expected_count'],
            (float) $signature['consistency_threshold'],
        );
    }

    /**
     * Apply the enrollment gates in user-actionable order — wrong count first,
     * then dissimilar signatures, then an edited/pasted image.
     *
     * @param  array<string, mixed>  $body
     */
    private function evaluate(array $body, int $expectedCount, float $consistencyThreshold): SignatureEnrollmentResult
    {
        $count = (int) ($body['count'] ?? 0);
        // isset() is false for a JSON null consistency (< 2 signatures) → stays null.
        $consistency = isset($body['consistency']) ? (float) $body['consistency'] : null;
        $centroid = $body['centroid'] ?? null;
        $samples = $body['signatures'] ?? [];
        $forensics = is_array($body['forensics'] ?? null)
            ? $body['forensics']
            : ['hard_flag' => false, 'reasons' => [], 'techniques' => []];

        $reason = match (true) {
            $count !== $expectedCount => self::REASON_COUNT,
            $consistency === null || $consistency < $consistencyThreshold => self::REASON_SIMILARITY,
            ! empty($forensics['hard_flag']) => self::REASON_EDITED,
            default => null,
        };

        return new SignatureEnrollmentResult(
            accepted: $reason === null,
            reason: $reason,
            count: $count,
            consistency: $consistency,
            centroid: is_array($centroid) ? $centroid : null,
            samples: is_array($samples) ? $samples : [],
            forensics: $forensics,
        );
    }
}
