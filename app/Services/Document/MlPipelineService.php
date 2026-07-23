<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\ValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * HTTP client for the FastAPI document-validation service (python/api). The whole
 * pipeline — classify, OCR, detect, signature, stamp, and Stage-T forensics — is
 * reached in ONE call to POST {base_url}/v1/validate (ADVS_System_Reference.md §5).
 *
 * This service is transport + mapping only: {@see validate()} performs the call and
 * returns the raw fail-forward body, and {@see mapStages()} projects the per-stage
 * result onto {@see ValidationResult} columns. The composite risk score
 * (Stage 5) stays in {@see RiskScoreService}; nothing here computes it.
 *
 * Reference vectors are read straight from their tables with the query builder (the
 * codebase has no Eloquent model for `vendor_embeddings` / `logo_references` /
 * `document_types` — the seeders use `DB::table` too).
 */
class MlPipelineService
{
    /**
     * Send a document to the ML API and return its fail-forward result.
     *
     * @return array{stages: array<string, mixed>, flags: list<string>, context: array{issuer_scope: string|null, logo_reference_id: int|null}}
     *
     * @throws RuntimeException on any transport or non-2xx failure (the caller fails forward).
     */
    public function validate(Document $document): array
    {
        $ml = config('advs.ml');

        $type = $this->resolveDocumentType($document);
        $issuerScope = $type['issuer_scope'] ?? null;
        // Only a national issuer can be scoped before OCR (its city is the '' sentinel);
        // an LGU city is unknown until OCR runs, so its logo can't be resolved in one pass.
        $city = $issuerScope === 'national' ? '' : null;
        [$stampReference, $logoReferenceId] = $this->resolveLogoReference(
            $document->document_type_id, $issuerScope, $city
        );

        $form = array_filter([
            'template' => $this->ocrTemplateFor($type['code'] ?? null),
            'document_type' => $type['code'] ?? null,
            'city' => $city,
            'signature_reference' => $this->resolveSignatureReference($document),
            'stamp_reference' => $stampReference,
            'forensics' => json_encode($this->forensicsContext(), JSON_THROW_ON_ERROR),
        ], static fn ($value): bool => $value !== null);

        try {
            $response = Http::baseUrl($ml['base_url'])
                ->withToken((string) $ml['token'])
                ->connectTimeout((int) ($ml['connect_timeout'] ?? 10))
                ->timeout((int) ($ml['timeout'] ?? 180))
                ->retry(max(1, (int) ($ml['retries'] ?? 1)), 200, throw: false)
                ->attach('file', Storage::disk('local')->get($document->file_path), $document->original_filename)
                ->post('/v1/validate', $form);
        } catch (ConnectionException $exc) {
            throw new RuntimeException("ML API unreachable at {$ml['base_url']}: {$exc->getMessage()}", previous: $exc);
        }

        if ($response->failed()) {
            throw new RuntimeException("ML API /v1/validate returned HTTP {$response->status()}.");
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['stages']) || ! is_array($body['stages'])) {
            throw new RuntimeException('ML API /v1/validate returned an unexpected body.');
        }

        return [
            'stages' => $body['stages'],
            'flags' => array_values($body['flags'] ?? []),
            'context' => ['issuer_scope' => $issuerScope, 'logo_reference_id' => $logoReferenceId],
        ];
    }

    /**
     * Project the API's per-stage result onto ValidationResult column values.
     *
     * @param  array<string, mixed>  $stages
     * @param  array{issuer_scope?: string|null, logo_reference_id?: int|null}  $context
     * @return array{columns: array<string, mixed>, flags: list<string>}
     */
    public function mapStages(array $stages, array $context = []): array
    {
        $columns = [];
        $flags = [];

        // ── Stage 4 detection — bounding boxes for the drill-down ──────────────
        $detections = $stages['detection']['detections'] ?? [];
        $columns['signature_bbox'] = $this->bestBox($detections, ['signature']);
        $columns['stamp_bbox'] = $this->bestBox($detections, ['stamp', 'logo']);

        // ── Stage 3 classification ─────────────────────────────────────────────
        $classification = $stages['classification'] ?? null;
        if ($this->ran($classification)) {
            $columns['classification_label'] = $classification['label'] ?? null;
            $columns['classification_confidence'] = $this->float($classification['confidence'] ?? null);
        }

        // ── Stage 2 OCR + field extraction ─────────────────────────────────────
        $ocr = $stages['ocr'] ?? null;
        if ($this->ran($ocr) && ! empty($ocr['pages'])) {
            $page = $ocr['pages'][0];
            $quality = $page['quality'] ?? [];
            $columns['ocr_extracted_text'] = $page['text'] ?? null;
            $columns['ocr_confidence'] = $this->float($quality['mean_confidence'] ?? null);
            $columns['text_validation_score'] = $this->float($quality['text_validation_score'] ?? null);
            $columns['text_fields_matched'] = $quality['required_matched'] ?? null;
            $columns['text_fields_expected'] = $quality['required_total'] ?? null;
        }

        // ── Stage 4a signature — calibrated authenticity, NOT raw similarity ───
        // similarity = 1/(1+distance) sits ~0.55 genuine / ~0.37 forged on the
        // unit sphere (M4_training_run_record.md), so feeding it to risk's
        // (1 - score) would penalise genuine signatures. Map from distance vs the
        // EER threshold instead.
        $signature = $stages['signature'] ?? null;
        if ($this->ran($signature)) {
            $distance = $this->float($signature['distance'] ?? null);
            $threshold = $this->float($signature['threshold'] ?? null);
            $columns['signature_detected'] = true;
            $columns['signature_distance'] = $distance;
            $columns['signature_passed'] = $signature['match'] ?? null;
            $columns['signature_score'] = $this->calibratedSignatureScore($distance, $threshold);
            if (($signature['match'] ?? null) === false) {
                $flags[] = 'signature_mismatch';
            }
        } elseif ($signature !== null) {
            $columns['signature_detected'] = false;
            if (($signature['reason'] ?? null) === 'no_reference_embedding') {
                // Distinct from a genuine detection miss: the region may well have
                // been found (signature_bbox above) — there's just no vendor
                // reference to compare it against yet.
                $flags[] = 'no_signature_reference';
            }
        }

        // ── Stage 4b issuer logo ───────────────────────────────────────────────
        $stamp = $stages['stamp'] ?? null;
        $issuerScope = $context['issuer_scope'] ?? null;
        if ($this->ran($stamp) && ($stamp['reason'] ?? null) === null) {
            $similarity = $this->float($stamp['similarity_score'] ?? null);
            $columns['stamp_detected'] = true;
            $columns['stamp_similarity'] = $similarity;
            $columns['stamp_score'] = $similarity;
            $columns['stamp_passed'] = $stamp['match'] ?? null;
            $columns['logo_reference_id'] = $context['logo_reference_id'] ?? null;
            if (($stamp['match'] ?? null) === false) {
                $flags[] = 'stamp_mismatch';
            }
        } elseif ($stamp !== null) {
            $columns['stamp_detected'] = false;
            $reason = $stamp['reason'] ?? null;
            if ($issuerScope === null) {
                // A document type with no issuer logo (e.g. financial statements)
                // is informational, not a fraud signal.
                $flags[] = 'no_issuer_logo';
            } elseif ($reason !== null) {
                $flags[] = $reason;                 // unreferenced_logo / no_stamp_detected
            }
        }

        return ['columns' => $columns, 'flags' => array_values(array_unique($flags))];
    }

    /**
     * Distance→authenticity in [0,1], calibrated to the EER threshold:
     * 1.0 at distance 0, 0.5 at the threshold, 0.0 at twice the threshold.
     */
    private function calibratedSignatureScore(?float $distance, ?float $threshold): ?float
    {
        if ($distance === null || $threshold === null || $threshold <= 0.0) {
            return null;
        }

        $score = 1.0 - $distance / (2.0 * $threshold);

        return round(max(0.0, min(1.0, $score)), 4);
    }

    /**
     * The highest-confidence detection box among the given labels, or null.
     *
     * @param  array<int, array<string, mixed>>  $detections
     * @param  list<string>  $labels
     * @return list<float>|null
     */
    private function bestBox(array $detections, array $labels): ?array
    {
        $best = null;
        foreach ($detections as $detection) {
            if (! in_array($detection['label'] ?? null, $labels, true)) {
                continue;
            }
            if ($best === null || ($detection['confidence'] ?? 0) > ($best['confidence'] ?? 0)) {
                $best = $detection;
            }
        }

        return $best['box'] ?? null;
    }

    /**
     * A stage "ran" when it is present and not a {skipped: true, ...} placeholder.
     *
     * @param  array<string, mixed>|null  $stage
     */
    private function ran(?array $stage): bool
    {
        return is_array($stage) && ($stage['skipped'] ?? false) !== true;
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * The OCR field template the API applies for a document type. The three
     * vendor-submittable types map to their own field template; anything else
     * (IDs, contracts, …) falls back to the configured default (see config/advs.php).
     */
    private function ocrTemplateFor(?string $code): string
    {
        return match ($code) {
            'bir_certificate' => 'bir',
            'business_permit' => 'business_permit',
            'dti_registration' => 'dti',
            default => (string) (config('advs.ml.template') ?? 'bir'),
        };
    }

    /**
     * Resolve the document's type code + issuer scope (query builder — no model).
     *
     * @return array{code: string, issuer_scope: string|null}|null
     */
    private function resolveDocumentType(Document $document): ?array
    {
        if ($document->document_type_id === null) {
            return null;
        }

        $row = DB::table('document_types')
            ->where('id', $document->document_type_id)
            ->first(['code', 'issuer_scope']);

        return $row === null ? null : ['code' => $row->code, 'issuer_scope' => $row->issuer_scope];
    }

    /**
     * The issuer's reference logo vector (raw JSON array string) + its row id, or
     * [null, null] when the issuer isn't resolvable in one pass or has no reference.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function resolveLogoReference(?int $documentTypeId, ?string $issuerScope, ?string $city): array
    {
        if ($documentTypeId === null || $issuerScope !== 'national' || $city === null) {
            return [null, null];
        }

        $row = DB::table('logo_references')
            ->where('document_type_id', $documentTypeId)
            ->where('city', $city)
            ->first(['id', 'feature_vector']);

        if ($row === null || $row->feature_vector === null) {
            return [null, null];
        }

        return [$row->feature_vector, (int) $row->id];
    }

    /**
     * The vendor's enrolled 128-D signature reference (raw JSON array string), or
     * null when enrollment hasn't happened yet (the API then skips signature).
     */
    private function resolveSignatureReference(Document $document): ?string
    {
        $embedding = DB::table('vendor_embeddings')
            ->where('vendor_id', $document->vendor_id)
            ->value('signature_embedding');

        return in_array($embedding, [null, '', 'null'], true) ? null : $embedding;
    }

    /**
     * Admin-tuned forensics config forwarded to the API's Stage-T so operator
     * overrides apply there too. Only the keys `tamper_analyze.run()` honours are
     * sent (`weights`, `tamper_threshold`); the hard-override stays Laravel-side in
     * {@see RiskScoreService} against `config('advs.risk.tamper_hard_threshold')`.
     *
     * @return array<string, mixed>
     */
    private function forensicsContext(): array
    {
        return [
            'weights' => config('advs.forensics.weights'),
            'tamper_threshold' => config('advs.forensics.tamper_authenticity_threshold'),
        ];
    }
}
