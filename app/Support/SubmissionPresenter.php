<?php

namespace App\Support;

use App\Models\Submission;
use App\Models\ValidationResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $summary['documents'] = $submission->documents->map(fn ($document): array => [
            'id' => $document->id,
            'name' => $document->original_filename,
            'type' => $typeNames[$document->document_type_id] ?? 'Unassigned',
            'pages' => max(1, (int) ($document->page_number ?? 1)),
            'size' => self::formatBytes((int) $document->file_size_bytes),
            'status' => $document->processing_status,
        ])->values()->all();

        $summary['ocr_excerpt'] = $result?->ocr_extracted_text
            ?? 'OCR stage not yet available — no extracted text for this submission.';

        $summary['risk_driver'] = self::riskDriver($summary['flags']);

        // §6 drill-down filter: one component set per document type present in
        // the submission, plus 'all' (the highest-risk document overall).
        $filters = [['key' => 'all', 'label' => 'All']];
        $sets = ['all' => self::components($result, $thresholds)];

        $byRisk
            ->groupBy(fn ($document) => $document->document_type_id ?? 0)
            ->map(fn ($group, $typeId): array => [
                'key' => $typeId === 0 ? 'unassigned' : 'type-'.$typeId,
                'label' => $typeId === 0 ? 'Unassigned' : ($typeNames[$typeId] ?? 'Unassigned'),
                'result' => $group->first()?->validationResult,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->each(function (array $type) use (&$filters, &$sets, $thresholds): void {
                $filters[] = ['key' => $type['key'], 'label' => $type['label']];
                $sets[$type['key']] = self::components($type['result'], $thresholds);
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
    private static function components(?ValidationResult $result, array $thresholds): array
    {
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
                'detected' => (bool) ($result?->signature_detected ?? false),
                'similarity' => $signatureSimilarity,
                'distance' => $result?->signature_distance !== null ? round($result->signature_distance, 3) : '—',
                'distance_threshold' => 'empirical',
                'pass' => (bool) ($result?->signature_passed ?? false),
                'detail' => match (true) {
                    $result === null || $result->signature_detected === null => 'Stage not yet available.',
                    ! $result->signature_detected => 'No signature region detected.',
                    default => 'Compared against the reference enrolled at registration.',
                },
            ],
            'stamp' => [
                'detected' => (bool) ($result?->stamp_detected ?? false),
                'similarity' => $stampSimilarity,
                'cosine' => $result?->stamp_similarity !== null ? round($result->stamp_similarity, 3) : '—',
                'pass' => (bool) ($result?->stamp_passed ?? false),
                'detail' => match (true) {
                    $result === null || $result->stamp_detected === null => 'Stage not yet available.',
                    ! $result->stamp_detected => 'No stamp/logo region detected.',
                    default => 'Compared against the issuer reference logo.',
                },
            ],
        ];
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
            ->unique()
            ->values()
            ->all();
    }

    public static function reference(Submission $submission): string
    {
        return sprintf('SUB-%05d', $submission->id);
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
