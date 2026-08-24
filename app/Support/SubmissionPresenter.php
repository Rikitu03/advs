<?php

namespace App\Support;

use App\Models\Submission;
use App\Models\ValidationResult;
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
        $submission->loadMissing(['vendor.user', 'reviewer', 'documents.validationResult', 'documents.tamperAnalysis']);

        $summary = self::summary($submission);

        $byRisk = $submission->documents
            ->sortByDesc(fn ($document) => (float) ($document->validationResult?->document_risk_score ?? -1))
            ->values();
        $primary = $byRisk->first();
        $result = $primary?->validationResult;
        $thresholds = config('advs.thresholds');

        $typeNames = self::typeNames();
        $signatureReferenceUrl = self::signatureReferenceUrl($submission->vendor_id);
        $logoReferenceUrls = self::logoReferenceUrls(
            $submission->documents
                ->pluck('validationResult.logo_reference_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );

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
            $summary['ocr_by_document'],
            $summary['ocr_fields_by_document'],
        ] = self::ocrByDocument($submission);

        $summary['flags_by_document'] = self::flagGroups($submission, $typeNames);

        $summary['risk_driver'] = self::riskDriver($summary['flags']);

        // §6 drill-down filter: one component set per document type present in
        // the submission, plus 'all' (the highest-risk document overall).
        $filters = [['key' => 'all', 'label' => 'All']];
        $sets = ['all' => self::components($result, $thresholds, $signatureReferenceUrl, $logoReferenceUrls)];

        $byRisk
            ->groupBy(fn ($document) => $document->document_type_id ?? 0)
            ->map(fn ($group, $typeId): array => [
                'key' => $typeId === 0 ? 'unassigned' : 'type-'.$typeId,
                'label' => $typeId === 0 ? 'Unassigned' : ($typeNames[$typeId] ?? 'Unassigned'),
                'result' => $group->first()?->validationResult,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->each(function (array $type) use (&$filters, &$sets, $thresholds, $signatureReferenceUrl, $logoReferenceUrls): void {
                $filters[] = ['key' => $type['key'], 'label' => $type['label']];
                $sets[$type['key']] = self::components($type['result'], $thresholds, $signatureReferenceUrl, $logoReferenceUrls);
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
        ?ValidationResult $result,
        array $thresholds,
        ?string $signatureReferenceUrl = null,
        array $logoReferenceUrls = [],
    ): array {
        $signatureSimilarity = $result?->signature_score !== null ? (int) round($result->signature_score * 100) : null;
        $stampSimilarity = $result?->stamp_score !== null ? (int) round($result->stamp_score * 100) : null;

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
                'crop' => self::cropData($result, $result?->signature_bbox),
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
                'crop' => self::cropData($result, $result?->stamp_bbox),
                'reference_image_url' => $result?->logo_reference_id === null
                    ? null
                    : ($logoReferenceUrls[$result->logo_reference_id] ?? null),
                'detail' => match (true) {
                    $result === null || $result->stamp_detected === null => 'Stage not yet available.',
                    $result->stamp_bbox === null => 'No stamp/logo region detected.',
                    $result->stamp_passed !== null => 'Compared against the issuer reference logo.',
                    default => 'Stamp/logo region detected, but no reference logo is on file for this issuer yet.',
                },
            ],
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
    private static function cropData(?ValidationResult $result, ?array $box): ?array
    {
        $document = $box !== null ? $result?->document : null;
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
     * @param  list<int>  $logoReferenceIds
     * @return array<int, string>
     */
    private static function logoReferenceUrls(array $logoReferenceIds): array
    {
        if ($logoReferenceIds === []) {
            return [];
        }

        return DB::table('logo_references')
            ->whereIn('id', $logoReferenceIds)
            ->pluck('reference_image_path', 'id')
            ->map(fn ($path): ?string => is_string($path) ? ltrim($path, '/') : null)
            ->filter(fn ($path): bool => $path !== null && str_starts_with($path, 'logo_references/') && Storage::disk('local')->exists($path))
            ->mapWithKeys(fn ($path, $id): array => [(int) $id => route('admin.logo-references.show', ['logoReference' => $id])])
            ->all();
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
            ->map(self::humanizeFlag(...))
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
                    ->map(self::humanizeFlag(...))
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
     * Returns the extracted key/value rows the panel renders, plus the raw text
     * behind them (kept for the collapsed "raw text" view, and the only thing to
     * show for results written before `ocr_fields` existed).
     *
     * @return array{
     *     0: list<array{key: string, label: string}>,
     *     1: array<string, string>,
     *     2: array<string, list<array<string, mixed>>>,
     * }
     */
    private static function ocrByDocument(Submission $submission): array
    {
        $filters = [];
        $texts = [];
        $fields = [];

        foreach ($submission->documents as $document) {
            $key = (string) $document->id;
            $result = $document->validationResult;

            $filters[] = ['key' => $key, 'label' => $document->original_filename];
            $texts[$key] = $result?->ocr_extracted_text
                ?? 'OCR stage not yet available — no extracted text for this document.';
            $fields[$key] = self::ocrFieldRows($result?->ocr_fields);
        }

        return [$filters, $texts, $fields];
    }

    /**
     * Flatten the API's field map into ordered rows for the key/value panel.
     * Template order is preserved (the map arrives in field-spec order), so a
     * document type always reads the same way.
     *
     * @param  array<string, mixed>|null  $fields
     * @return list<array{key: string, label: string, value: string|null, required: bool, warning: array{label: string, reasons: list<string>}|null}>
     */
    private static function ocrFieldRows(?array $fields): array
    {
        $rows = [];

        foreach ($fields ?? [] as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $value = $field['value'] ?? null;

            $rows[] = [
                'key' => (string) $key,
                // The API names each field as the form itself captions it
                // ("Registered Activity(ies)"); humanizeFieldName is the fallback
                // for a payload written before names were sent.
                'label' => $field['name'] ?? self::humanizeFieldName((string) $key),
                'value' => ($value === null || $value === '') ? null : (string) $value,
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
    private static function humanizeFlag(string $flag): string
    {
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

        $acronyms = ['tin' => 'TIN', 'rdo' => 'RDO', 'no' => 'No', 'id' => 'ID',
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
        return DB::table('document_types')->pluck('name', 'id');
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
