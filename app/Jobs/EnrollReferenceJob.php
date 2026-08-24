<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Document\MlPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Seeds an ISSUER's reference logo from the first officer-APPROVED document that
 * carries it (ADVS_System_Reference.md §5 Stage 4b).
 *
 * References are keyed by issuer, never by vendor. `document_types.issuer_scope`
 * decides the key:
 *   - `national` (BIR, SEC, DTI, FDA…) → one reference per document type, stored
 *     against the `''` city sentinel;
 *   - `lgu` (business/sanitary permits) → one per (document type, city), using the
 *     city OCR detected in Stage 2;
 *   - `null` (e.g. a signed contract) → no issuer logo exists; nothing to seed.
 *
 * Every unmet precondition is a silent, logged skip rather than a failure: this runs
 * *after* an officer's decision is already committed, so it must never be able to
 * undo or block that decision. Genuine transport failures do throw, so the queue
 * retries them.
 */
class EnrollReferenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 120;

    public function __construct(
        public Document $document,
        public ?int $officerId = null,
    ) {
        $this->onQueue('document-processing');
    }

    public function handle(MlPipelineService $mlPipeline): void
    {
        $type = DB::table('document_types')
            ->where('id', $this->document->document_type_id)
            ->first(['id', 'code', 'name', 'issuer_scope']);

        if ($type === null || $type->issuer_scope === null) {
            return; // no issuer logo is expected for this type
        }

        $result = $this->document->validationResult;
        $box = $result?->stamp_bbox;

        if (! is_array($box) || count($box) !== 4) {
            return; // Stage 4 found no logo region to seed from
        }

        $city = $this->resolveCity($type->issuer_scope, $result->detected_city);

        if ($city === null) {
            // An LGU permit whose city OCR could not identify: the issuer cannot be
            // keyed, so there is nothing to seed against (§5 Stage 4b failure paths).
            return;
        }

        if ($this->referenceExists((int) $type->id, $city)) {
            return; // the issuer's reference was seeded by an earlier approval
        }

        $embedding = $mlPipeline->embedStamp($this->document, $box);

        $path = $this->storeCrop($city, $type->code, $embedding['crop']);

        // insertOrIgnore + the unique(document_type_id, city) index makes concurrent
        // approvals safe: the loser silently no-ops instead of failing the job.
        $inserted = DB::table('logo_references')->insertOrIgnore([
            'document_type_id' => (int) $type->id,
            'city' => $city,
            'label' => $this->label($type, $city),
            'feature_vector' => json_encode($embedding['vector'], JSON_THROW_ON_ERROR),
            'reference_image_path' => $path,
            'seeded_from_document_id' => $this->document->id,
            'enrolled_by' => $this->officerId,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            Storage::disk('local')->delete($path);

            return;
        }

        Log::info('Seeded issuer reference logo', [
            'document_type' => $type->code,
            'city' => $city === '' ? '(national)' : $city,
            'document_id' => $this->document->id,
        ]);
    }

    /**
     * The issuer's city key, or null when it cannot be determined.
     *
     * National issuers use the `''` sentinel — NOT NULL is deliberate, since MySQL
     * treats NULL as distinct in a unique index and would allow duplicate national
     * rows for one document type.
     */
    private function resolveCity(string $issuerScope, ?string $detectedCity): ?string
    {
        if ($issuerScope === 'national') {
            return '';
        }

        $city = trim((string) $detectedCity);

        return $city === '' ? null : $city;
    }

    private function referenceExists(int $documentTypeId, string $city): bool
    {
        return DB::table('logo_references')
            ->where('document_type_id', $documentTypeId)
            ->where('city', $city)
            ->exists();
    }

    /**
     * Persist the crop the API embedded as the issuer's reference image. Falls back
     * to a copy of the source document when the API returned no crop, because
     * `logo_references.reference_image_path` is NOT NULL.
     */
    private function storeCrop(string $city, string $typeCode, ?string $crop): string
    {
        $slug = $city === '' ? 'national' : str($city)->slug()->value();
        $path = "logo_references/{$typeCode}/{$slug}-".now()->timestamp.'.png';

        Storage::disk('local')->put(
            $path,
            $crop ?? Storage::disk('local')->get($this->document->file_path),
        );

        return $path;
    }

    private function label(object $type, string $city): string
    {
        return $city === ''
            ? (string) $type->name
            : "{$city} {$type->name}";
    }

    public function failed(Throwable $e): void
    {
        // The officer's decision is already committed and must stand; a failure here
        // only means the issuer stays unreferenced and the next approval retries it.
        Log::error('EnrollReferenceJob failed; issuer reference not seeded', [
            'document_id' => $this->document->id,
            'document_type_id' => $this->document->document_type_id,
            'error' => $e->getMessage(),
        ]);
    }
}
