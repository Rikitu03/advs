<?php

namespace App\Support;

use App\Models\Document;
use App\Models\PipelineRun;
use App\Models\Submission;
use App\Models\ValidationResult;
use App\Models\Vendor;
use App\Services\Document\IssuerCity;
use App\Services\Document\OcrVendorMatchScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Maps Eloquent submissions onto the array shape the admin Volt pages render
 * (queue rows, drill-down, archive). Kept in one place so the Pending queue,
 * Archived Reports, Vendor Profiles, and the drill-down all describe a
 * submission identically (ADVS_System_Reference.md §4/§6).
 */
class SubmissionPresenter
{
    /** @var array<string, string> */
    private const BIR_OCR_FIELDS = [
        'tin' => 'TIN',
        'registered_name' => 'Registered Name',
        'registered_address' => 'Registered Address',
        'trade_name' => 'Trade Name',
        'line_of_business' => 'Line of Business / PSIC',
        'registration_date' => 'Registration Date',
        'date_issued' => 'Date Issued',
    ];

    /** @var array<string, string> */
    private const DTI_OCR_FIELDS = [
        'owner_representative_name' => 'Owner / Representative Name',
        'business_name' => 'Business Name',
        'business_address' => 'Business Address',
        'date_issued' => 'Valid Date',
        'expiry_date' => 'Expiration Date',
        'trn_no' => 'Transaction Reference Number (TRN)',
    ];

    /**
     * Compact row for queue/archive tables. Pass a preloaded id→name map of
     * document types when presenting many rows.
     *
     * @param  Collection<int|string, string>|null  $typeNames
     * @return array<string, mixed>
     */
    public static function summary(Submission $submission, ?Collection $typeNames = null): array
    {
        $typeNames ??= self::typeNames();
        $submission->loadMissing(['vendor.user', 'reviewer', 'documents.validationResult']);

        $types = $submission->documents
            ->pluck('document_type_id')
            ->map(fn ($id) => $typeNames[$id] ?? 'Unassigned')
            ->unique()
            ->values();

        return [
            'id' => $submission->id,
            'ref' => self::reference($submission),
            'company' => $submission->vendor?->company_name ?? '—',
            'vendor' => $submission->vendor?->user?->name ?? '—',
            'vendor_id' => $submission->vendor_id,
            'document_type' => $types->isEmpty() ? '—' : $types->implode(', '),
            'documents_count' => $submission->documents->count(),
            'submitted_at' => $submission->created_at,
            'flags' => self::flags($submission),
            'risk_score' => $submission->composite_risk_score !== null
                ? (int) round((float) $submission->composite_risk_score)
                : null,
            'risk_level' => $submission->risk_level,
            'status' => $submission->status,
            'decision' => match ($submission->status) {
                Submission::STATUS_APPROVED => 'approved',
                Submission::STATUS_RESUBMISSION_REQUESTED => 'resubmission_requested',
                default => null,
            },
            'reviewed_by' => $submission->reviewer?->name,
            'reviewed_at' => $submission->reviewed_at,
            'review_comments' => $submission->review_comments,
        ];
    }

    /**
     * Full drill-down shape for the validation-report page (§6): summary plus
     * the component breakdown, per-file list, and OCR excerpt taken from the
     * highest-risk document's validation result.
     *
     * @return array<string, mixed>
     */
    public static function detail(Submission $submission): array
    {
        $submission->loadMissing([
            'vendor.user',
            'vendor.representative',
            'reviewer',
            'documents.validationResult',
            'documents.tamperAnalysis',
        ]);

        $summary = self::summary($submission);

        $byRisk = $submission->documents
            ->sortByDesc(fn ($document) => (float) ($document->validationResult?->document_risk_score ?? -1))
            ->values();
        $primary = $byRisk->first();
        $thresholds = config('advs.thresholds');

        $typeMetadata = self::typeMetadata();
        $typeNames = $typeMetadata->map(fn (array $type): string => $type['name']);
        $signatureReferenceUrl = self::signatureReferenceUrl($submission->vendor_id);
        $logoReferences = self::logoReferences(
            $submission->documents
                ->pluck('document_type_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
        $stampEvidence = self::latestStampEvidence($submission->documents);

        $summary['documents'] = $submission->documents->map(fn ($document): array => [
            'id' => $document->id,
            'name' => $document->original_filename,
            'type' => $typeNames[$document->document_type_id] ?? 'Unassigned',
            'pages' => max(1, (int) ($document->page_number ?? 1)),
            'size' => self::formatBytes((int) $document->file_size_bytes),
            'status' => $document->processing_status,
            'mime' => $document->mime_type,
            'kind' => self::previewKind($document->mime_type),
        ])->values()->all();

        [
            $summary['ocr_filters'],
            $summary['ocr_fields_by_document'],
        ] = self::ocrByDocument($submission, $typeMetadata);

        $summary['flags_by_document'] = self::flagGroups($submission, $typeNames);

        $summary['risk_driver'] = self::riskDriver($summary['flags']);

        // §6 drill-down filter: one component set per document type present in
        // the submission, plus 'all' (the highest-risk document overall).
        $filters = [['key' => 'all', 'label' => 'All']];
        $sets = ['all' => self::components(
            $primary,
            $thresholds,
            $signatureReferenceUrl,
            $typeMetadata,
            $logoReferences,
            $stampEvidence,
        )];

        $byRisk
            ->groupBy(fn ($document) => $document->document_type_id ?? 0)
            ->map(fn ($group, $typeId): array => [
                'key' => $typeId === 0 ? 'unassigned' : 'type-'.$typeId,
                'label' => $typeId === 0 ? 'Unassigned' : ($typeNames[$typeId] ?? 'Unassigned'),
                'document' => $group->first(),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->each(function (array $type) use (&$filters, &$sets, $thresholds, $signatureReferenceUrl, $typeMetadata, $logoReferences, $stampEvidence): void {
                $filters[] = ['key' => $type['key'], 'label' => $type['label']];
                $sets[$type['key']] = self::components(
                    $type['document'],
                    $thresholds,
                    $signatureReferenceUrl,
                    $typeMetadata,
                    $logoReferences,
                    $stampEvidence,
                );
            });

        $summary['component_filters'] = $filters;
        $summary['component_sets'] = $sets;
        $summary['components'] = $sets['all'];

        return $summary;
    }

    /**
     * Component-breakdown rows for one validation result (§6). A null result
     * renders every stage as "not yet available".
     *
     * @param  array<string, float|string>  $thresholds
     * @return array<string, array<string, mixed>>
     */
    private static function components(
        ?Document $document,
        array $thresholds,
        ?string $signatureReferenceUrl = null,
        ?Collection $typeMetadata = null,
        ?Collection $logoReferences = null,
        array $stampEvidence = [],
    ): array {
        $result = $document?->validationResult;
        $signatureSimilarity = $result?->signature_score !== null ? (int) round($result->signature_score * 100) : null;
        $stampSimilarity = $result?->stamp_score !== null ? (int) round($result->stamp_score * 100) : null;
        $signatureComparisons = self::signatureComparisons($document, $result, $signatureReferenceUrl);
        $references = self::stampReferences(
            $document,
            $typeMetadata ?? collect(),
            $logoReferences ?? collect(),
            $document === null ? [] : ($stampEvidence[$document->id] ?? []),
        );
        $stampComparisons = self::stampComparisons(
            $document,
            $result,
            $typeMetadata ?? collect(),
            $logoReferences ?? collect(),
            $stampEvidence,
        );

        return [
            'text' => [
                'score' => $result?->text_validation_score !== null ? (int) round($result->text_validation_score * 100) : null,
                'pass' => $result?->text_validation_score !== null
                    && $result->text_validation_score >= (float) $thresholds['text'],
                'detail' => $result?->text_validation_score !== null
                    ? sprintf('%d of %d expected fields matched', (int) $result->text_fields_matched, (int) $result->text_fields_expected)
                    : 'Stage not yet available.',
            ],
            'classification' => [
                'confidence' => $result?->classification_confidence !== null ? (int) round($result->classification_confidence * 100) : null,
                'pass' => $result?->classification_confidence !== null
                    && $result->classification_confidence >= (float) $thresholds['classification'],
                'detail' => $result?->classification_label !== null
                    ? "Classified as {$result->classification_label}"
                    : 'Stage not yet available.',
            ],
            'signature' => [
                // 'detected' = YOLOv8 found the region (signature_bbox); 'verified' =
                // the Siamese comparison actually ran. These differ whenever a region
                // was found but no vendor reference is enrolled yet — don't blame the
                // detector for a missing-reference condition (see MlPipelineService).
                'detected' => $result !== null && $result->signature_bbox !== null,
                'verified' => (bool) ($result?->signature_detected ?? false),
                'similarity' => $signatureSimilarity,
                'distance' => $result?->signature_distance !== null ? round($result->signature_distance, 3) : '—',
                'distance_threshold' => 'empirical',
                'pass' => (bool) ($result?->signature_passed ?? false),
                'crop' => self::cropData($document, $result?->signature_bbox),
                'comparisons' => $signatureComparisons,
                'reference_image_url' => $signatureReferenceUrl,
                'detail' => match (true) {
                    $result === null || $result->signature_detected === null => 'Stage not yet available.',
                    $result->signature_bbox === null => 'No signature region detected.',
                    $result->signature_passed !== null => 'Compared against the reference enrolled at registration.',
                    default => 'Signature region detected, but no reference is enrolled for this vendor yet.',
                },
            ],
            'stamp' => [
                'detected' => $result !== null && $result->stamp_bbox !== null,
                'verified' => (bool) ($result?->stamp_detected ?? false),
                'similarity' => $stampSimilarity,
                'cosine' => $result?->stamp_similarity !== null ? round($result->stamp_similarity, 3) : '—',
                'similarity_threshold' => (int) round((float) $thresholds['stamp'] * 100),
                'pass' => (bool) ($result?->stamp_passed ?? false),
                'texture_checked' => $result?->stamp_tampered !== null,
                'scan_copy_texture' => (bool) ($result?->stamp_tampered ?? false),
                'crop' => self::cropData($document, $result?->stamp_bbox),
                'references' => $references,
                'comparisons' => $stampComparisons,
                'reference_image_url' => $references[0]['url'] ?? null,
                'detail' => match (true) {
                    $result === null || $result->stamp_detected === null => 'Stage not yet available.',
                    $result->stamp_bbox === null => 'No stamp/logo region detected.',
                    $result->stamp_passed !== null => 'Compared against the issuer reference logo.',
                    $references !== [] => 'Issuer reference images are available, but this stored result has no comparison score.',
                    default => 'Stamp/logo region detected, but no reference logo is on file for this issuer yet.',
                },
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function signatureComparisons(
        ?Document $document,
        ?ValidationResult $result,
        ?string $referenceImageUrl,
    ): array {
        $stored = $result?->signature_comparisons;

        if (is_array($stored) && $stored !== []) {
            return collect($stored)
                ->filter(fn (mixed $comparison): bool => is_array($comparison))
                ->map(function (array $comparison) use ($document, $referenceImageUrl): array {
                    $distance = $comparison['distance'] ?? null;
                    $score = $comparison['score'] ?? $comparison['similarity'] ?? null;

                    return [
                        'page_index' => $comparison['page_index'] ?? null,
                        'confidence' => $comparison['confidence'] ?? null,
                        'match' => $comparison['match'] ?? null,
                        'pass' => (bool) ($comparison['match'] ?? false),
                        'similarity' => $score !== null ? (int) round((float) $score * 100) : null,
                        'distance' => $distance !== null ? round((float) $distance, 3) : '—',
                        'distance_threshold' => $comparison['threshold'] ?? 'empirical',
                        'crop' => self::cropData($document, $comparison['box'] ?? null),
                        'reference_image_url' => $referenceImageUrl,
                    ];
                })
                ->sortBy([
                    ['page_index', 'asc'],
                    ['confidence', 'desc'],
                ])
                ->values()
                ->all();
        }

        if ($result?->signature_bbox === null && $result?->signature_detected !== true) {
            return [];
        }

        return [[
            'page_index' => null,
            'confidence' => null,
            'match' => $result?->signature_passed,
            'pass' => (bool) ($result?->signature_passed ?? false),
            'similarity' => $result?->signature_score !== null ? (int) round($result->signature_score * 100) : null,
            'distance' => $result?->signature_distance !== null ? round($result->signature_distance, 3) : '—',
            'distance_threshold' => 'empirical',
            'crop' => self::cropData($document, $result?->signature_bbox),
            'reference_image_url' => $referenceImageUrl,
        ]];
    }

    /**
     * Normalize one issuer-reference comparison per detected stamp/logo.
     * Older rows only have singular aggregate fields, so they receive one
     * compatibility item rather than fabricated comparisons.
     *
     * @return list<array<string, mixed>>
     */
    private static function stampComparisons(
        ?Document $document,
        ?ValidationResult $result,
        Collection $typeMetadata,
        Collection $logoReferences,
        array $stampEvidence,
    ): array {
        $stored = $result?->stamp_comparisons;

        if (is_array($stored) && $stored !== []) {
            return collect($stored)
                ->filter(fn (mixed $comparison): bool => is_array($comparison))
                ->map(function (array $comparison) use ($document, $typeMetadata, $logoReferences): array {
                    $matches = collect($comparison['reference_matches'] ?? [])
                        ->filter(fn (mixed $match): bool => is_array($match) && is_string($match['key'] ?? null))
                        ->keyBy('key')
                        ->all();
                    $references = self::stampReferences(
                        $document,
                        $typeMetadata,
                        $logoReferences,
                        [
                            'best_reference_key' => $comparison['best_reference_key'] ?? null,
                            'matches' => $matches,
                        ],
                    );

                    return self::formatStampComparison($document, $comparison, $references);
                })
                ->values()
                ->all();
        }

        if ($result?->stamp_bbox === null && $result?->stamp_detected !== true) {
            return [];
        }

        $aggregate = $stampEvidence[$document?->id] ?? [];
        $references = self::stampReferences($document, $typeMetadata, $logoReferences, $aggregate);

        return [self::formatStampComparison($document, [
            'page_index' => null,
            'box' => $result?->stamp_bbox,
            'confidence' => null,
            'match' => $result?->stamp_passed,
            'similarity_score' => $result?->stamp_similarity,
            'threshold' => null,
            'stamp_tampered' => $result?->stamp_tampered,
            'best_reference_key' => $aggregate['best_reference_key'] ?? null,
        ], $references)];
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @param  list<array<string, mixed>>  $references
     * @return array<string, mixed>
     */
    private static function formatStampComparison(?Document $document, array $comparison, array $references): array
    {
        $similarity = is_numeric($comparison['similarity_score'] ?? null)
            ? (float) $comparison['similarity_score']
            : null;

        return [
            'page_index' => $comparison['page_index'] ?? null,
            'confidence' => $comparison['confidence'] ?? null,
            'match' => $comparison['match'] ?? null,
            'pass' => (bool) ($comparison['match'] ?? false),
            'similarity' => $similarity === null ? null : (int) round($similarity * 100),
            'cosine' => $similarity === null ? '—' : round($similarity, 3),
            'similarity_threshold' => isset($comparison['threshold']) && is_numeric($comparison['threshold'])
                ? (int) round((float) $comparison['threshold'] * 100)
                : null,
            'texture_checked' => $comparison['stamp_tampered'] !== null,
            'scan_copy_texture' => (bool) ($comparison['stamp_tampered'] ?? false),
            'crop' => self::cropData($document, $comparison['box'] ?? null),
            'references' => $references,
            'reference_image_url' => $references[0]['url'] ?? null,
        ];
    }

    /**
     * Cropped-region preview data for a detected signature/stamp box: the
     * authenticated document-stream URL plus the pixel box to crop to. Bbox
     * pixel coordinates come from YOLOv8 running on the uploaded raster
     * image, so they only line up 1:1 with a raster upload — a PDF's box is
     * relative to its 300-DPI *rendered* page, not the PDF bytes an <img>
     * would load, so PDFs get no crop preview (text detail only).
     *
     * @param  list<float>|null  $box
     * @return array{url: string, box: list<float>}|null
     */
    private static function cropData(?Document $document, ?array $box): ?array
    {
        if ($document === null || self::previewKind($document->mime_type) !== 'image' || ! self::validBoundingBox($box)) {
            return null;
        }

        return [
            'url' => route('admin.documents.show', $document->id),
            'box' => $box,
        ];
    }

    /**
     * Inference output is external input; malformed boxes must not break the
     * officer report renderer or produce invalid CSS dimensions.
     *
     * @param  list<float>|null  $box
     */
    private static function validBoundingBox(?array $box): bool
    {
        if ($box === null || count($box) !== 4 || ! collect($box)->every(fn ($value): bool => is_numeric($value) && is_finite((float) $value))) {
            return false;
        }

        return (float) $box[0] >= 0.0
            && (float) $box[1] >= 0.0
            && (float) $box[2] > (float) $box[0]
            && (float) $box[3] > (float) $box[1];
    }

    private static function signatureReferenceUrl(int $vendorId): ?string
    {
        $path = DB::table('vendor_embeddings')
            ->where('vendor_id', $vendorId)
            ->value('signature_image_path');
        $path = is_string($path) ? ltrim($path, '/') : null;

        return $path !== null && str_starts_with($path, 'signatures/') && Storage::disk('local')->exists($path)
            ? route('admin.signature.show', ['vendor' => $vendorId])
            : null;
    }

    /**
     * @param  list<int>  $documentTypeIds
     * @return Collection<int, array{id: int, document_type_id: int, city: string, label: string|null, url: string}>
     */
    private static function logoReferences(array $documentTypeIds): Collection
    {
        if ($documentTypeIds === []) {
            return collect();
        }

        return DB::table('logo_references')
            ->whereIn('document_type_id', $documentTypeIds)
            ->get(['id', 'document_type_id', 'city', 'label', 'reference_image_path'])
            ->mapWithKeys(function (object $reference): array {
                $path = is_string($reference->reference_image_path) ? ltrim($reference->reference_image_path, '/') : null;
                if ($path === null || ! str_starts_with($path, 'logo_references/') || ! Storage::disk('local')->exists($path)) {
                    return [];
                }

                return [(int) $reference->id => [
                    'id' => (int) $reference->id,
                    'document_type_id' => (int) $reference->document_type_id,
                    'city' => is_string($reference->city) ? $reference->city : '',
                    'label' => is_string($reference->label) ? $reference->label : null,
                    'url' => route('admin.logo-references.show', ['logoReference' => $reference->id]),
                ]];
            });
    }

    /**
     * @param  Collection<int|string, array{name: string, code: string, issuer_scope: string|null}>  $typeMetadata
     * @param  Collection<int, array{id: int, document_type_id: int, city: string, label: string|null, url: string}>  $logoReferences
     * @param  array{best_reference_key?: string|null, matches?: array<string, array<string, mixed>>}  $evidence
     * @return list<array{key: string, label: string, source: string, url: string, similarity: int|null, cosine: float|null, match: bool|null, best: bool}>
     */
    private static function stampReferences(
        ?Document $document,
        Collection $typeMetadata,
        Collection $logoReferences,
        array $evidence,
    ): array {
        if ($document === null || $document->document_type_id === null) {
            return [];
        }

        $type = $typeMetadata[$document->document_type_id] ?? null;
        if (! is_array($type)) {
            return [];
        }

        $matches = is_array($evidence['matches'] ?? null) ? $evidence['matches'] : [];
        $bestReferenceKey = is_string($evidence['best_reference_key'] ?? null)
            ? $evidence['best_reference_key']
            : null;
        $curated = app(IssuerLogoCatalog::class)->referencesFor(
            $type['code'],
            $type['issuer_scope'],
            $document->validationResult?->detected_city,
        );

        if ($curated !== []) {
            $reference = collect($curated)->first(
                fn (array $candidate): bool => $candidate['key'] === $bestReferenceKey,
            ) ?? ($bestReferenceKey === null ? $curated[0] : null);

            if ($reference === null) {
                return [];
            }

            $match = is_array($matches[$reference['key']] ?? null) ? $matches[$reference['key']] : null;
            $similarity = is_numeric($match['similarity_score'] ?? null)
                ? (float) $match['similarity_score']
                : null;

            return [[
                'key' => $reference['key'],
                'label' => $reference['label'],
                'source' => 'curated',
                'url' => route('admin.issuer-logo-references.show', ['reference' => $reference['key']]),
                'similarity' => $similarity === null ? null : (int) round($similarity * 100),
                'cosine' => $similarity === null ? null : round($similarity, 3),
                'match' => is_bool($match['match'] ?? null) ? $match['match'] : null,
                'best' => true,
            ]];
        }

        $databaseReference = self::databaseLogoReference($document, $type, $logoReferences);
        if ($databaseReference === null) {
            return [];
        }

        $match = is_array($matches['enrolled-reference'] ?? null) ? $matches['enrolled-reference'] : null;
        $similarity = is_numeric($match['similarity_score'] ?? null)
            ? (float) $match['similarity_score']
            : null;

        return [[
            'key' => 'enrolled-reference',
            'label' => $databaseReference['label']
                ?? ($type['issuer_scope'] === 'lgu' && $databaseReference['city'] !== ''
                    ? $databaseReference['city'].' issuer reference'
                    : $type['name'].' issuer reference'),
            'source' => 'enrolled',
            'url' => $databaseReference['url'],
            'similarity' => $similarity === null ? null : (int) round($similarity * 100),
            'cosine' => $similarity === null ? null : round($similarity, 3),
            'match' => is_bool($match['match'] ?? null) ? $match['match'] : null,
            'best' => $bestReferenceKey === 'enrolled-reference',
        ]];
    }

    /**
     * @param  array{name: string, code: string, issuer_scope: string|null}  $type
     * @param  Collection<int, array{id: int, document_type_id: int, city: string, label: string|null, url: string}>  $logoReferences
     * @return array{id: int, document_type_id: int, city: string, label: string|null, url: string}|null
     */
    private static function databaseLogoReference(Document $document, array $type, Collection $logoReferences): ?array
    {
        $resultReferenceId = $document->validationResult?->logo_reference_id;
        if ($resultReferenceId !== null && $logoReferences->has($resultReferenceId)) {
            return $logoReferences[$resultReferenceId];
        }

        $city = $type['issuer_scope'] === 'lgu'
            ? IssuerCity::canonical($document->validationResult?->detected_city)
            : '';

        return $logoReferences->first(function (array $reference) use ($document, $type, $city): bool {
            if ($reference['document_type_id'] !== $document->document_type_id) {
                return false;
            }

            return $type['issuer_scope'] === 'lgu'
                ? $city !== null && IssuerCity::canonical($reference['city']) === $city
                : $reference['city'] === '';
        });
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return array<int, array{best_reference_key: string|null, matches: array<string, array<string, mixed>>}>
     */
    private static function latestStampEvidence(Collection $documents): array
    {
        $documentIds = $documents->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($documentIds === []) {
            return [];
        }

        $runs = PipelineRun::query()
            ->with(['pages' => fn ($query) => $query->orderBy('page_index')])
            ->whereIn('document_id', $documentIds)
            ->where('status', PipelineRun::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('document_id');

        $evidence = [];
        foreach ($runs as $run) {
            $bestReferenceKey = null;
            $matches = [];

            foreach ($run->pages as $page) {
                $stamp = is_array($page->stages['stamp'] ?? null) ? $page->stages['stamp'] : [];
                if ($bestReferenceKey === null && is_string($stamp['best_reference_key'] ?? null)) {
                    $bestReferenceKey = $stamp['best_reference_key'];
                }

                foreach ((array) ($stamp['reference_matches'] ?? []) as $match) {
                    if (! is_array($match) || ! is_string($match['key'] ?? null)) {
                        continue;
                    }

                    $key = $match['key'];
                    $current = $matches[$key]['similarity_score'] ?? null;
                    $candidate = $match['similarity_score'] ?? null;
                    if (! is_numeric($candidate) || (is_numeric($current) && (float) $current >= (float) $candidate)) {
                        continue;
                    }

                    $matches[$key] = $match;
                }
            }

            $evidence[(int) $run->document_id] = [
                'best_reference_key' => $bestReferenceKey,
                'matches' => $matches,
            ];
        }

        return $evidence;
    }

    /**
     * Distinct flags across all of the submission's validation results.
     *
     * @return list<string>
     */
    public static function flags(Submission $submission): array
    {
        return $submission->documents
            ->flatMap(fn ($document): array => $document->validationResult?->flags ?? [])
            ->map(self::flagLabel(...))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Flags grouped by the document that raised them, in file order. Documents
     * with no flags are omitted; flag tokens are humanised (see humanizeFlag()).
     *
     * @param  Collection<int|string, string>  $typeNames
     * @return list<array{document: string, type: string, flags: list<string>}>
     */
    private static function flagGroups(Submission $submission, Collection $typeNames): array
    {
        return $submission->documents
            ->map(fn ($document): array => [
                'document' => $document->original_filename,
                'type' => $typeNames[$document->document_type_id] ?? 'Unassigned',
                'flags' => collect($document->validationResult?->flags ?? [])
                    ->map(self::flagLabel(...))
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['flags'] !== [])
            ->values()
            ->all();
    }

    /**
     * Per-document OCR output for the drill-down's filter tabs, in file order —
     * one tab per document (keyed by document id), since OCR output is
     * intrinsically per-file, not per-type.
     *
     * Returns only the extracted key/value rows rendered by the panel. Raw OCR
     * text remains persisted for pipeline use but is not exposed in Livewire state.
     *
     * @return array{
     *     0: list<array{key: string, label: string}>,
     *     1: array<string, list<array<string, mixed>>>,
     * }
     */
    private static function ocrByDocument(Submission $submission, Collection $typeMetadata): array
    {
        $filters = [];
        $fields = [];

        foreach ($submission->documents as $document) {
            $key = (string) $document->id;

            $filters[] = ['key' => $key, 'label' => $document->original_filename];
            $fields[$key] = self::ocrFieldRows(
                $document->validationResult?->ocr_fields,
                $submission->vendor,
                $typeMetadata[$document->document_type_id]['code'] ?? null,
            );
        }

        return [$filters, $fields];
    }

    /**
     * Flatten the API's field map into ordered rows for the key/value panel.
     * Template order is preserved (the map arrives in field-spec order), so a
     * document type always reads the same way.
     *
     * @param  array<string, mixed>|null  $fields
     * @return list<array{key: string, label: string, value: string|null, required: bool, warning: array{label: string, reasons: list<string>}|null}>
     */
    private static function ocrFieldRows(?array $fields, ?Vendor $vendor = null, ?string $documentTypeCode = null): array
    {
        $rows = [];

        $profile = match ($documentTypeCode) {
            'bir_certificate' => self::BIR_OCR_FIELDS,
            'dti_registration' => self::DTI_OCR_FIELDS,
            default => null,
        };

        if ($profile !== null) {
            $fields = collect($profile)
                ->mapWithKeys(function (string $label, string $key) use ($fields): array {
                    $field = is_array($fields[$key] ?? null) ? $fields[$key] : [];
                    $field['name'] = $label;
                    $field['value'] ??= null;

                    return [$key => $field];
                })
                ->all();
        }

        $candidateResolver = new OcrVendorCandidateResolver;

        foreach ($fields ?? [] as $key => $field) {
            if (! is_array($field)) {
                continue;
            }
            if (str_starts_with((string) $key, '__')) {
                continue;
            }

            $value = $field['value'] ?? null;
            $candidates = $candidateResolver->resolve((string) $key, $vendor);
            $comparison = $value === null || $value === '' || $candidates === []
                ? null
                : collect($candidates)->contains(
                    fn (array $candidate): bool => OcrVendorMatchScore::compare(
                        $value === null ? null : (string) $value,
                        $candidate['value'],
                        $candidate['is_address'],
                    ) === true,
                );

            $rows[] = [
                'key' => (string) $key,
                // The API names each field as the form itself captions it
                // ("Registered Activity(ies)"); humanizeFieldName is the fallback
                // for a payload written before names were sent.
                'label' => (string) $key === 'trn_no'
                    ? 'Transaction Reference Number (TRN)'
                    : ($field['name'] ?? self::humanizeFieldName((string) $key)),
                'value' => ($value === null || $value === '') ? null : (string) $value,
                'registration_key' => $candidates === [] ? null : implode('|', array_column($candidates, 'key')),
                'registration_label' => $candidates === []
                    ? null
                    : implode(' / ', array_values(array_unique(array_column($candidates, 'label')))),
                'registration_value' => $candidates === []
                    ? null
                    : implode(' / ', array_values(array_unique(array_column($candidates, 'value')))),
                'comparison' => $comparison,
                'required' => (bool) ($field['required'] ?? false),
                'warning' => self::fieldWarning($field),
            ];
        }

        return $rows;
    }

    /**
     * Chip label + officer-readable reasons for one field's warning tokens, or
     * null when the pipeline had nothing to say about the value. Tokens are
     * raised in Python (ocr_dryrun.annotate_field_warnings) so the format
     * patterns that judge a value live with the field specs that define it.
     *
     * Listed in priority order — the chip shows the first token present, the
     * tooltip lists them all.
     *
     * @param  array<string, mixed>  $field
     * @return array{label: string, reasons: list<string>}|null
     */
    private static function fieldWarning(array $field): ?array
    {
        $catalog = [
            'format_mismatch' => ['Format', 'Value does not match the format expected for this field.'],
            'noisy_text' => ['Noisy', 'Value contains character patterns typical of noisy OCR output.'],
            'not_found' => ['Not found', 'This required field was not found in the document.'],
            'low_confidence' => ['Low confidence', 'OCR read this value with low confidence.'],
        ];

        $raised = array_filter((array) ($field['warnings'] ?? []), 'is_string');
        $tokens = array_values(array_intersect(array_keys($catalog), $raised));

        if ($tokens === []) {
            return null;
        }

        $confidence = $field['confidence'] ?? null;

        return [
            'label' => $catalog[$tokens[0]][0],
            'reasons' => array_map(
                fn (string $token): string => $token === 'low_confidence' && $confidence !== null
                    ? sprintf('OCR read this value with low confidence (%d%%).', (int) round((float) $confidence))
                    : $catalog[$token][1],
                $tokens,
            ),
        ];
    }

    /**
     * Turns a machine flag token into an officer-readable phrase. Tokens already
     * written as prose (they contain a space) are left untouched.
     *
     * - "missing_required_fields:form_no,tin" → "Missing required fields: Form No, TIN"
     * - "no_signature_detected"               → "No signature detected"
     */
    public static function flagLabel(string $flag): string
    {
        $labels = [
            'stamp_tampered' => 'Stamp has scan/copy texture',
            'stamp_tamper_unavailable' => 'Stamp texture check unavailable',
        ];

        if (isset($labels[$flag])) {
            return $labels[$flag];
        }

        if (str_contains($flag, ' ')) {
            return $flag;
        }

        if (str_starts_with($flag, 'missing_required_fields:')) {
            $fields = array_filter(
                explode(',', substr($flag, strlen('missing_required_fields:'))),
                fn (string $field): bool => trim($field) !== '',
            );

            return 'Missing required fields: '.implode(', ', array_map(self::humanizeFieldName(...), $fields));
        }

        return ucfirst(str_replace('_', ' ', $flag));
    }

    /**
     * Humanises a snake_case field key, preserving domain acronyms (TIN, RDO, OCN,
     * TRN). A few keys don't tokenise cleanly and get a whole-key label instead.
     */
    private static function humanizeFieldName(string $field): string
    {
        $field = trim($field);

        $overrides = [
            'trn_no' => 'TRN',
            'tax_types' => 'Registered Activities',
        ];

        if (isset($overrides[$field])) {
            return $overrides[$field];
        }

        $acronyms = ['tin' => 'TIN', 'dti' => 'DTI', 'sec' => 'SEC', 'rdo' => 'RDO', 'no' => 'No', 'id' => 'ID',
            'ocn' => 'OCN', 'trn' => 'TRN', 'psic' => 'PSIC'];

        $words = array_map(
            fn (string $word): string => $acronyms[$word] ?? ucfirst($word),
            explode('_', $field),
        );

        return implode(' ', $words);
    }

    public static function reference(Submission $submission): string
    {
        return sprintf('SUB-%05d', $submission->id);
    }

    /**
     * Which in-browser preview a stored file supports: 'image' and 'pdf' render
     * inline; anything else ('other') falls back to a download prompt.
     */
    private static function previewKind(?string $mime): string
    {
        return match (true) {
            $mime !== null && str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            default => 'other',
        };
    }

    /**
     * @return Collection<int|string, string>
     */
    public static function typeNames(): Collection
    {
        return self::typeMetadata()->map(fn (array $type): string => $type['name']);
    }

    /**
     * @return Collection<int|string, array{name: string, code: string, issuer_scope: string|null}>
     */
    private static function typeMetadata(): Collection
    {
        return once(fn (): Collection => DB::table('document_types')
            ->get(['id', 'name', 'code', 'issuer_scope'])
            ->mapWithKeys(fn (object $type): array => [(int) $type->id => [
                'name' => (string) $type->name,
                'code' => (string) $type->code,
                'issuer_scope' => is_string($type->issuer_scope) ? $type->issuer_scope : null,
            ]]));
    }

    /**
     * Human-readable file size without the intl extension (unavailable on the
     * local XAMPP PHP build, so Number::fileSize() cannot be used).
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1024 / 1024, 2).' MB';
    }

    /**
     * @param  list<string>  $flags
     */
    private static function riskDriver(array $flags): string
    {
        if ($flags === []) {
            return 'All components within thresholds.';
        }

        if (in_array('Document tampering suspected', $flags, true)) {
            return 'Driven by forensic tampering evidence.';
        }

        return 'Driven by: '.implode('; ', array_slice($flags, 0, 2)).(count($flags) > 2 ? '…' : '');
    }
}
