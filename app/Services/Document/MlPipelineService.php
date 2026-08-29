<?php

namespace App\Services\Document;

use App\Jobs\EnrollReferenceJob;
use App\Models\Document;
use App\Models\ValidationResult;
use App\Services\SystemSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
    public function __construct(private readonly SystemSettingsService $settings) {}

    /**
     * Send a document to the ML API and return its fail-forward result.
     *
     * @return array{stages: array<string, mixed>, flags: list<string>, context: array{issuer_scope: string|null, logo_reference_ids: array<string, int>}}
     *
     * @throws RuntimeException on any transport or non-2xx failure (the caller fails forward).
     */
    public function validate(Document $document, ?array $settingsSnapshot = null): array
    {
        $ml = config('advs.ml');
        $settingsSnapshot ??= $this->settings->pipelineSnapshot();

        $type = $this->resolveDocumentType($document);
        $issuerScope = $type['issuer_scope'] ?? null;
        // Only a national issuer has a city knowable here (the '' sentinel); an LGU
        // city prints on the document, so it does not exist until the API's own
        // Stage 2 reads it — which is why the whole reference set is sent below and
        // the API, not this service, picks the row to compare against.
        $city = $issuerScope === 'national' ? '' : null;
        [$stampReferences, $logoReferenceIds] = $this->resolveLogoReferences(
            $document->document_type_id, $issuerScope
        );

        // Build form data explicitly to ensure signature reference is properly handled
        $form = [];

        if (($typeCode = $type['code'] ?? null) !== null) {
            $form['template'] = $this->ocrTemplateFor($typeCode);
            $form['document_type'] = $typeCode;
        }

        if ($issuerScope !== null) {
            $form['issuer_scope'] = $issuerScope;
        }

        if ($city !== null) {
            $form['city'] = $city;
        }

        // Handle signature reference explicitly - ensure it's sent even if it's an empty string
        $signatureReference = $this->resolveSignatureReference($document);
        if ($signatureReference !== null) {
            $form['signature_reference'] = $signatureReference;
        }

        if ($stampReferences !== null) {
            $form['stamp_references'] = $stampReferences;
        }

        $form['forensics'] = json_encode($this->forensicsContext($settingsSnapshot), JSON_THROW_ON_ERROR);
        $form['settings_snapshot'] = json_encode($settingsSnapshot, JSON_THROW_ON_ERROR);

        try {
            $response = $this->client()
                ->attach('file', Storage::disk('local')->get($document->file_path), $document->original_filename)
                ->post('/v1/validate', $form);
        } catch (ConnectionException $exc) {
            throw new MlApiException(
                "ML API /v1/validate unreachable at {$ml['base_url']} for document {$document->id}: {$exc->getMessage()}",
                retryable: true,
                previous: $exc,
            );
        }

        if ($response->failed()) {
            $this->throwForFailure('/v1/validate', $response, "document {$document->id}");
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['stages']) || ! is_array($body['stages'])) {
            throw new RuntimeException('ML API /v1/validate returned an unexpected body.');
        }

        return [
            'schema_version' => is_string($body['schema_version'] ?? null) ? $body['schema_version'] : null,
            'status' => is_string($body['status'] ?? null) ? $body['status'] : 'completed',
            'stages' => $body['stages'],
            'flags' => array_values($body['flags'] ?? []),
            'pages' => is_array($body['pages'] ?? null) ? array_values($body['pages']) : [],
            'models' => is_array($body['models'] ?? null) ? $body['models'] : [],
            'settings_hash' => is_string($body['settings_hash'] ?? null) ? $body['settings_hash'] : null,
            'timings' => is_array($body['timings'] ?? null) ? $body['timings'] : [],
            'context' => ['issuer_scope' => $issuerScope, 'logo_reference_ids' => $logoReferenceIds],
        ];
    }

    /**
     * Embed the issuer logo found at $box inside $document (Stage 4b reference
     * seeding — {@see EnrollReferenceJob}).
     *
     * The FULL document is sent with the box rather than a PHP-side crop: the box
     * comes from Stage 4 detection, whose coordinates are in the space of the page
     * image the API rendered, so for a PDF only the API can reproduce that space.
     * It returns the crop it embedded, which the caller persists as the issuer's
     * `reference_image_path` — so the stored image is provably the embedded pixels.
     *
     * @param  list<float>  $box  [x1, y1, x2, y2] in page-image coordinates
     * @return array{vector: list<float>, crop: string|null} crop = raw PNG bytes
     *
     * @throws RuntimeException on any transport or non-2xx failure (the job retries).
     */
    public function embedStamp(Document $document, array $box): array
    {
        $ml = config('advs.ml');

        try {
            $response = $this->client()
                ->attach('file', Storage::disk('local')->get($document->file_path), $document->original_filename)
                ->post('/v1/stamp/embed', ['box' => json_encode(array_values($box), JSON_THROW_ON_ERROR)]);
        } catch (ConnectionException $exc) {
            throw new MlApiException(
                "ML API /v1/stamp/embed unreachable at {$ml['base_url']} for document {$document->id}: {$exc->getMessage()}",
                retryable: true,
                previous: $exc,
            );
        }

        if ($response->failed()) {
            $this->throwForFailure('/v1/stamp/embed', $response, "document {$document->id}");
        }

        $vector = $response->json('vector');
        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('ML API /v1/stamp/embed returned no feature vector.');
        }

        $crop = $response->json('crop_png_base64');

        return [
            'vector' => array_map(static fn (mixed $value): float => (float) $value, $vector),
            'crop' => is_string($crop) && $crop !== '' ? base64_decode($crop, true) ?: null : null,
        ];
    }

    /**
     * The shared, env-configured ML API client (base URL, bearer token, timeouts,
     * retries) used by every call in this service.
     */
    private function client(): PendingRequest
    {
        $ml = config('advs.ml');
        $attempts = max(1, (int) ($ml['retries'] ?? 1) + 1);

        return Http::baseUrl($ml['base_url'])
            ->withToken((string) $ml['token'])
            ->connectTimeout((int) ($ml['connect_timeout'] ?? 10))
            ->timeout((int) ($ml['timeout'] ?? 180))
            ->retry(
                $attempts,
                static fn (int $attempt): int => min(1000 * (2 ** max(0, $attempt - 1)), 5000),
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && $exception->response?->serverError() === true;
                },
                throw: false,
            );
    }

    private function throwForFailure(string $endpoint, Response $response, string $context): never
    {
        $detail = $response->json('detail');
        $message = is_scalar($detail)
            ? (string) $detail
            : (is_array($detail) ? json_encode($detail) : trim($response->body()));
        $message = $message !== '' ? Str::limit($message, 500) : 'no response body';

        throw new MlApiException(
            "ML API {$endpoint} returned HTTP {$response->status()} for {$context}: {$message}",
            retryable: $response->serverError(),
            status: $response->status(),
        );
    }

    /**
     * Project the API's per-stage result onto ValidationResult column values.
     *
     * @param  array<string, mixed>  $stages
     * @param  array{issuer_scope?: string|null, logo_reference_ids?: array<string, int>}  $context
     * @return array{columns: array<string, mixed>, flags: list<string>}
     */
    public function mapStages(array $stages, array $context = []): array
    {
        $columns = [
            'ocr_extracted_text' => null,
            'ocr_confidence' => null,
            'ocr_fields' => null,
            'detected_city' => null,
        ];
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
            // 1 - P(fake). Distinct from confidence: a document confidently
            // classified `fake` is 0.98 confident and 0.02 authentic.
            $columns['classification_authenticity'] = $this->float($classification['authenticity'] ?? null);
        }

        // ── Stage 2 OCR + field extraction ─────────────────────────────────────
        $ocr = $stages['ocr'] ?? null;
        if ($this->ran($ocr) && ! empty($ocr['pages'])) {
            $page = $ocr['pages'][0];
            $quality = $page['quality'] ?? [];
            $sourcePages = is_array($ocr['source_pages'] ?? null) ? $ocr['source_pages'] : [];
            $pages = $sourcePages !== [] ? $sourcePages : $ocr['pages'];
            $columns['ocr_extracted_text'] = collect($pages)
                ->pluck('text')
                ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
                ->implode("\n\n");
            $columns['ocr_confidence'] = $this->float($quality['mean_confidence'] ?? null);
            // The structured key/value map the drill-down renders. Stored as the
            // API returns it — per-field warnings included — so the format
            // patterns that grade a value stay in the field specs that define it.
            $fields = $page['fields'] ?? [];
            $permitEvidence = $ocr['business_permit'] ?? null;
            if (is_array($permitEvidence)) {
                $fields['__business_permit'] = $permitEvidence;
            }
            $columns['ocr_fields'] = $fields === [] ? null : $fields;

            // The issuing city an LGU logo reference is keyed by (§5 Stage 4b). It is
            // knowable only here — the city prints on the document, so it does not
            // exist until Stage 2 has read it — which is why the pre-call lookup in
            // validate() can scope national issuers but never LGU ones.
            $city = is_array($permitEvidence)
                ? $this->normalizeIssuerCity($permitEvidence['issuer_city_canonical'] ?? null)
                : $this->detectedCity($page['fields'] ?? null);
            if ($city !== null) {
                $columns['detected_city'] = $city;
            }
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
        // §5 Stage 4b's texture check runs reference or not, so its verdict is
        // read before the reference branch below. Null means the classifier
        // could not run — leave the column alone rather than recording "clean".
        if ($this->ran($stamp) && ($stamp['stamp_tampered'] ?? null) !== null) {
            $columns['stamp_tampered'] = (bool) $stamp['stamp_tampered'];
        }
        if ($this->ran($stamp) && ($stamp['reason'] ?? null) === null) {
            $similarity = $this->float($stamp['similarity_score'] ?? null);
            $columns['stamp_detected'] = true;
            $columns['stamp_similarity'] = $similarity;
            $columns['stamp_score'] = $similarity;
            $columns['stamp_passed'] = $stamp['match'] ?? null;
            $columns['logo_reference_id'] = ($stamp['reference_source'] ?? 'enrolled') === 'enrolled'
                ? $this->matchedLogoReferenceId(
                    $context['logo_reference_ids'] ?? [], $issuerScope, $stamp['city'] ?? null
                )
                : null;
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
     * The issuing city read by Stage 2, canonicalised, or null when this run did
     * not read one.
     *
     * Returns null rather than an empty value on a miss so the caller can leave the
     * column untouched: a re-run whose OCR degraded must not erase a city an earlier
     * run read off the same document. Note that `''` is NOT "no city" here — it is
     * the national-issuer sentinel in `logo_references.city`, so it must never be
     * written from a failed read.
     *
     * Case and spacing are canonicalised at this single writer because the value
     * becomes half of the unique `(document_type_id, city)` key: "CITY OF DIGOS"
     * and "City of  Digos" have to resolve to one issuer, and the stored form is
     * what {@see EnrollReferenceJob} labels the reference with.
     *
     * @param  array<string, mixed>|null  $fields  the API's Stage-2 field map
     */
    private function detectedCity(?array $fields): ?string
    {
        $field = $fields['city_issued'] ?? null;

        if (! is_array($field) || ($field['matched'] ?? false) !== true) {
            return null;
        }

        return $this->normalizeIssuerCity($field['value'] ?? null);
    }

    private function normalizeIssuerCity(mixed $value): ?string
    {
        return IssuerCity::canonical(is_string($value) ? $value : null);
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
            default => 'none',
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
     * Every reference logo this issuer has, keyed by city, plus each row's id.
     *
     * A `national` issuer has exactly one row under the `''` city sentinel; an
     * `lgu` issuer has one per city, and which one applies depends on the city
     * printed on the document — which does not exist until Stage 2 OCR runs,
     * inside the same API call. So the whole set travels with the request and
     * the API picks; {@see matchedLogoReferenceId()} then records which.
     *
     * @return array{0: string|null, 1: array<string, int>} [JSON {city: vector}, city => id]
     */
    private function resolveLogoReferences(?int $documentTypeId, ?string $issuerScope): array
    {
        if ($documentTypeId === null || $issuerScope === null) {
            return [null, []];
        }

        $vectors = [];
        $ids = [];

        $rows = DB::table('logo_references')
            ->where('document_type_id', $documentTypeId)
            ->whereNotNull('feature_vector')
            ->get(['id', 'city', 'feature_vector']);

        foreach ($rows as $row) {
            $vector = json_decode((string) $row->feature_vector, true);
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            $key = $issuerScope === 'national'
                ? ''
                : (IssuerCity::canonical((string) $row->city) ?? Str::title(Str::squish((string) $row->city)));
            if ($key === null) {
                continue;
            }
            $vectors[$key] = $vector;
            $ids[$key] = (int) $row->id;
        }

        return [$vectors === [] ? null : json_encode($vectors, JSON_THROW_ON_ERROR), $ids];
    }

    /**
     * The `logo_references` row the API actually compared against: the `''`
     * sentinel row for a national issuer, or the row for the city Stage 2 read.
     *
     * @param  array<string, int>  $ids  city => logo_references.id
     */
    private function matchedLogoReferenceId(array $ids, ?string $issuerScope, ?string $city): ?int
    {
        if ($issuerScope === 'national') {
            return $ids[''] ?? null;
        }

        $key = $this->normalizeIssuerCity($city);

        if ($key === null) {
            return null;
        }

        if (isset($ids[$key])) {
            return $ids[$key];
        }

        foreach ($ids as $storedCity => $id) {
            if ($this->normalizeIssuerCity((string) $storedCity) === $key) {
                return $id;
            }
        }

        return null;
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
    private function forensicsContext(array $snapshot): array
    {
        return [
            'weights' => [
                'metadata' => (float) ($snapshot['TAMPER_WEIGHT_METADATA'] ?? 0.20),
                'ela' => (float) ($snapshot['TAMPER_WEIGHT_ELA'] ?? 0.25),
                'copy_move' => (float) ($snapshot['TAMPER_WEIGHT_COPY_MOVE'] ?? 0.25),
                'font' => (float) ($snapshot['TAMPER_WEIGHT_FONT'] ?? 0.15),
                'cross_reference' => (float) ($snapshot['TAMPER_WEIGHT_CROSS_REFERENCE'] ?? 0.15),
            ],
            'tamper_threshold' => (float) ($snapshot['TAMPER_AUTHENTICITY_THRESHOLD'] ?? 0.50),
            'hard_confidence' => (float) ($snapshot['TAMPER_HARD_THRESHOLD'] ?? 0.80),
        ];
    }
}
