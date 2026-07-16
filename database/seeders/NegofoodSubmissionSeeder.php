<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\TamperAnalysis;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Gives the seeded Negofood Demo Trading vendor (vendor@advs.test) a realistic
 * submission history backed by REAL files, so the vendor portal and the
 * officer dashboard demo end-to-end straight after `migrate:fresh --seed`:
 *
 * - one PENDING_REVIEW batch (all three document types, standby-pipeline shape
 *   mirroring what a live upload produces today: null ML components, forensic
 *   flags, medium composite risk),
 * - one APPROVED and one REJECTED submission for history/archive pages,
 * - the matching vendor + officer notifications.
 *
 * Sample files live in database/seeders/assets/negofood/ (watermarked
 * "SAMPLE — DEMO ONLY") and are copied into the vendor's private storage so
 * document previews and downloads work. Skips itself if the vendor already
 * has submissions (idempotent for repeated `db:seed` runs).
 */
class NegofoodSubmissionSeeder extends Seeder
{
    private const STANDBY_FLAGS = [
        'Text validation unavailable',
        'Classification unavailable',
        'Signature verification unavailable',
        'Stamp verification unavailable',
    ];

    private const NO_METADATA_FLAG = 'No provenance metadata present — may have been stripped to hide edits';

    public function run(): void
    {
        $vendor = User::query()->where('email', 'vendor@advs.test')->first()?->vendor;

        if ($vendor === null || $vendor->submissions()->exists()) {
            return;
        }

        $officer = User::query()
            ->where('role', User::ROLE_COMPLIANCE_OFFICER)
            ->orderBy('id')
            ->first();
        $typeIds = DB::table('document_types')->pluck('id', 'code');
        $vendorUserId = $vendor->user_id;

        $rejected = $this->seedRejected($vendor, $officer, $typeIds);
        $approved = $this->seedApproved($vendor, $officer, $typeIds);
        $pending = $this->seedPending($vendor, $typeIds);

        $vendor->update(['status' => Vendor::STATUS_UNDER_REVIEW]);

        $this->notify($vendorUserId, Notification::TYPE_DECISION_MADE, 'Submission rejected',
            'Your financial statement submission was rejected. Review the officer comments before resubmitting.',
            $rejected->id, $rejected->reviewed_at);
        $this->notify($vendorUserId, Notification::TYPE_DECISION_MADE, 'Submission approved',
            'Your BIR Certificate of Registration was approved and added to your vendor record.',
            $approved->id, $approved->reviewed_at);
        $this->notify($vendorUserId, Notification::TYPE_SUBMISSION_RECEIVED, 'Submission received',
            'Your submission has been received and is being processed.',
            $pending->id, $pending->created_at);
        $this->notify($vendorUserId, Notification::TYPE_PROCESSING_COMPLETE, 'Processing complete',
            'Your submission has been processed and is awaiting review.',
            $pending->id, $pending->created_at->copy()->addMinutes(2));

        if ($officer !== null) {
            $this->notify($officer->id, Notification::TYPE_HIGH_RISK_ALERT, 'High-risk submission',
                sprintf('High-risk submission detected (score: 84/100) from %s.', $vendor->company_name),
                $rejected->id, $rejected->created_at);
            $this->notify($officer->id, Notification::TYPE_GENERAL, 'Submission ready for review',
                sprintf('Submission from %s is ready for review.', $vendor->company_name),
                $pending->id, $pending->created_at->copy()->addMinutes(2));
        }
    }

    /**
     * The showcase batch: all three document types in one submission, shaped
     * like a live standby-pipeline run (Stage T only; ML components null).
     *
     * @param  Collection<string, int>  $typeIds
     */
    private function seedPending(Vendor $vendor, $typeIds): Submission
    {
        $submittedAt = now()->subHours(2);

        $submission = Submission::create([
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_PENDING_REVIEW,
            'composite_risk_score' => 60.0,
            'risk_level' => 'medium',
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ]);

        $documents = [
            // [type code, asset, stored name, risk, extra flags, tamper score]
            ['business_permit', 'business_permit.png', 'business_permit_negofood_2026_01.png', 58.0, [self::NO_METADATA_FLAG], 0.15],
            ['bir_certificate', 'bir_certificate.png', 'bir_certificate_negofood_2026_02.png', 52.0, [self::NO_METADATA_FLAG], 0.12],
            ['financial_statement', 'financial_statement.pdf', 'audited_financial_statement_2025_03.pdf', 60.0, [], 0.0],
        ];

        foreach ($documents as [$typeCode, $asset, $storedName, $risk, $extraFlags, $tamperScore]) {
            $document = $this->storeDocument($vendor, $submission, $typeIds[$typeCode] ?? null, $asset, $storedName, $submittedAt);

            // Standby shape: every ML component null (models not wired yet) —
            // exactly what ProcessDocumentAction records for live uploads today.
            ValidationResult::factory()->create([
                'document_id' => $document->id,
                'submission_id' => $submission->id,
                'ocr_extracted_text' => null,
                'ocr_confidence' => null,
                'text_validation_score' => null,
                'text_fields_matched' => null,
                'text_fields_expected' => null,
                'classification_label' => null,
                'classification_confidence' => null,
                'signature_detected' => false,
                'signature_score' => null,
                'signature_passed' => null,
                'stamp_detected' => false,
                'stamp_score' => null,
                'stamp_passed' => null,
                'document_risk_score' => $risk,
                'flags' => [...$extraFlags, ...self::STANDBY_FLAGS],
            ]);

            TamperAnalysis::factory()->create([
                'document_id' => $document->id,
                'submission_id' => $submission->id,
                'tamper_score' => $tamperScore,
                'tamper_authenticity' => 1 - $tamperScore,
                'tamper_confidence' => $tamperScore,
                'metadata_result' => $extraFlags === []
                    ? ['score' => 1.0, 'pass' => true, 'flags' => []]
                    : ['score' => 0.5, 'pass' => false, 'flags' => $extraFlags],
                'flags' => $extraFlags,
            ]);
        }

        return $submission;
    }

    /**
     * @param  Collection<string, int>  $typeIds
     */
    private function seedApproved(Vendor $vendor, ?User $officer, $typeIds): Submission
    {
        $submittedAt = now()->subDays(9);

        $submission = Submission::create([
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_APPROVED,
            'composite_risk_score' => 18.0,
            'risk_level' => 'low',
            'reviewed_by' => $officer?->id,
            'reviewed_at' => $submittedAt->copy()->addDay(),
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt->copy()->addDay(),
        ]);

        $document = $this->storeDocument(
            $vendor, $submission, $typeIds['bir_certificate'] ?? null,
            'bir_certificate.png', 'bir_certificate_registration_2026.png', $submittedAt,
        );

        // Illustrative full-pipeline result (factory default: all components pass).
        ValidationResult::factory()->create([
            'document_id' => $document->id,
            'submission_id' => $submission->id,
            'classification_label' => 'BIR Permit',
            'document_risk_score' => 18.0,
            'flags' => [],
        ]);

        return $submission;
    }

    /**
     * @param  Collection<string, int>  $typeIds
     */
    private function seedRejected(Vendor $vendor, ?User $officer, $typeIds): Submission
    {
        $submittedAt = now()->subDays(18);

        $submission = Submission::create([
            'vendor_id' => $vendor->id,
            'status' => Submission::STATUS_REJECTED,
            'composite_risk_score' => 84.0,
            'risk_level' => 'high',
            'reviewed_by' => $officer?->id,
            'reviewed_at' => $submittedAt->copy()->addDays(2),
            'review_comments' => 'Copy-move tampering evidence could not be cleared on manual review. Please resubmit a clean scan of the audited financial statement.',
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt->copy()->addDays(2),
        ]);

        $document = $this->storeDocument(
            $vendor, $submission, $typeIds['financial_statement'] ?? null,
            'financial_statement.pdf', 'audited_financial_statement_2025.pdf', $submittedAt,
        );

        ValidationResult::factory()->create([
            'document_id' => $document->id,
            'submission_id' => $submission->id,
            'classification_label' => 'Financial Statement',
            'document_risk_score' => 84.0,
            'flags' => ['Document tampering suspected', 'Copy-move: 48 cloned keypoints'],
        ]);

        TamperAnalysis::factory()->tampered()->create([
            'document_id' => $document->id,
            'submission_id' => $submission->id,
        ]);

        return $submission;
    }

    /**
     * Copy a sample asset into the vendor's private storage and create the
     * Document row pointing at the real stored file.
     */
    private function storeDocument(
        Vendor $vendor,
        Submission $submission,
        ?int $typeId,
        string $asset,
        string $storedName,
        Carbon $timestamp,
    ): Document {
        $contents = File::get(database_path("seeders/assets/negofood/{$asset}"));
        $path = "vendor{$vendor->id}/{$storedName}";

        Storage::disk('local')->put($path, $contents);

        return Document::create([
            'submission_id' => $submission->id,
            'vendor_id' => $vendor->id,
            'document_type_id' => $typeId,
            'original_filename' => $storedName,
            'file_path' => $path,
            'mime_type' => str_ends_with($asset, '.pdf') ? 'application/pdf' : 'image/png',
            'file_size_bytes' => strlen($contents),
            'page_number' => 1,
            'processing_status' => Document::STATUS_COMPLETED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function notify(int $userId, string $type, string $subject, string $body, int $submissionId, Carbon $at): void
    {
        Notification::factory()->create([
            'user_id' => $userId,
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'related_submission_id' => $submissionId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
