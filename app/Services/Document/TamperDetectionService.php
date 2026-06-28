<?php

namespace App\Services\Document;

use App\Models\Document;
use App\Models\TamperAnalysis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage T — runs the document-tampering forensics over a document's ORIGINAL
 * upload by invoking {@see python/scripts/tamper_analyze.py} through the Process
 * facade (the same pattern as the other Stage runners, CLAUDE.md §6), then maps
 * the JSON verdict onto a {@see TamperAnalysis} row.
 *
 * The forensic layer must read the ORIGINAL file (compression/metadata signals)
 * and full-DPI page images — never the binarized `converted_image_path`, which
 * has those signals stripped out.
 */
class TamperDetectionService
{
    /**
     * Build the JSON payload the Python runner consumes for a document.
     *
     * @param  list<array{text: string, conf: float|int, bbox: array<int, int>}>  $ocrWords
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $pageImages  Full-DPI rendered page images (PDFs); defaults to the original for raster uploads.
     * @return array<string, mixed>
     */
    public function buildPayload(
        Document $document,
        ?string $ocrText = null,
        array $ocrWords = [],
        array $fields = [],
        ?string $issueDate = null,
        array $pageImages = [],
    ): array {
        $originalPath = Storage::disk('local')->path($document->file_path);

        if ($pageImages === []) {
            // Raster uploads ARE their own page image; PDFs need rendered pages
            // supplied by the orchestrator — never the binarized converted image.
            $isRaster = str_starts_with((string) $document->mime_type, 'image/');
            $pageImages = $isRaster ? [$originalPath] : [];
        }

        return [
            'original_path' => $originalPath,
            'page_images' => $pageImages,
            'ocr_text' => $ocrText,
            'ocr_words' => $ocrWords,
            'fields' => $fields,
            'issue_date' => $issueDate,
            'weights' => config('advs.forensics.weights'),
            'tamper_threshold' => config('advs.forensics.tamper_authenticity_threshold'),
            'hard_confidence' => config('advs.forensics.hard_confidence'),
        ];
    }

    /**
     * Run the forensic pipeline for a document and return the aggregate verdict.
     *
     * @param  array<string, mixed>  $payload  Optional pre-built payload; built from the document when omitted.
     * @return array<string, mixed>
     */
    public function analyze(Document $document, ?array $payload = null): array
    {
        $payload ??= $this->buildPayload($document);

        $token = $document->id.'_'.Str::random(8);
        $dir = storage_path('app/python_payloads');
        File::ensureDirectoryExists($dir);
        $payloadPath = "{$dir}/tamper_{$token}.json";
        $outputPath = "{$dir}/tamper_{$token}_result.json";

        try {
            File::put($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $result = Process::path(base_path('python'))
                ->timeout((int) config('advs.forensics.timeout', 120))
                ->run(sprintf(
                    '%s %s --input %s --output %s',
                    config('advs.forensics.python_bin', 'python3'),
                    config('advs.forensics.script', 'scripts/tamper_analyze.py'),
                    escapeshellarg($payloadPath),
                    escapeshellarg($outputPath),
                ));

            if ($result->failed() || ! File::exists($outputPath)) {
                Log::error('Stage T tamper_analyze.py failed', [
                    'document_id' => $document->id,
                    'exit_code' => $result->exitCode(),
                    'stderr' => $result->errorOutput(),
                ]);
                throw new RuntimeException('Document tampering analysis script failed.');
            }

            return json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            File::delete([$payloadPath, $outputPath]);
        }
    }

    /**
     * Persist a forensic verdict (the {@see analyze()} return value) onto a
     * one-to-one {@see TamperAnalysis} row for the document.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function persist(Document $document, array $verdict): TamperAnalysis
    {
        $techniques = $verdict['techniques'] ?? [];

        return $document->tamperAnalysis()->updateOrCreate(
            ['document_id' => $document->id],
            [
                'submission_id' => $document->submission_id,
                'tamper_score' => $verdict['tamper_score'] ?? null,
                'tamper_authenticity' => $verdict['tamper_authenticity'] ?? null,
                'tamper_confidence' => $verdict['tamper_confidence'] ?? null,
                'hard_flag' => (bool) ($verdict['hard_flag'] ?? false),
                'tamper_passed' => $verdict['tamper_passed'] ?? null,
                'metadata_result' => $techniques['metadata'] ?? null,
                'ela_result' => $techniques['ela'] ?? null,
                'copy_move_result' => $techniques['copy_move'] ?? null,
                'font_result' => $techniques['font'] ?? null,
                'cross_reference_result' => $techniques['cross_reference'] ?? null,
                'flags' => $verdict['flags'] ?? [],
            ],
        );
    }
}
