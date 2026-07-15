<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\ValidationResult;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Populates a realistic spread of vendors, submissions, documents, and
 * validation results so the officer dashboard demos well without waiting for
 * live uploads. NOT part of the default `db:seed` run — invoke explicitly:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DocumentTypeSeeder::class);

        $typeIds = DB::table('document_types')->pluck('id')->all();

        $profiles = [
            // [company, status, submission status, risk, level, flags]
            ['Santos Trading Corp.', Vendor::STATUS_UNDER_REVIEW, Submission::STATUS_PENDING_REVIEW, 78.0, 'high', ['Document tampering suspected', 'Signature verification unavailable']],
            ['Cruz Logistics Inc.', Vendor::STATUS_UNDER_REVIEW, Submission::STATUS_PENDING_REVIEW, 47.5, 'medium', ['Text validation unavailable']],
            ['Mendoza Pharma', Vendor::STATUS_UNDER_REVIEW, Submission::STATUS_PENDING_REVIEW, 12.0, 'low', []],
            ['Garcia Textiles', Vendor::STATUS_APPROVED, Submission::STATUS_APPROVED, 18.0, 'low', []],
            ['Tan Imports', Vendor::STATUS_REJECTED, Submission::STATUS_REJECTED, 84.0, 'high', ['Document tampering suspected', 'Stamp verification unavailable']],
        ];

        $officer = User::query()->where('role', User::ROLE_COMPLIANCE_OFFICER)->first()
            ?? User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        foreach ($profiles as [$company, $vendorStatus, $submissionStatus, $risk, $level, $flags]) {
            $user = User::factory()->role(User::ROLE_VENDOR)->create([
                'signature_path' => 'signatures/demo-reference.jpg',
                'signature_enrolled_at' => now()->subMonths(2),
                'vendor_profile_completed_at' => now()->subMonths(2),
            ]);

            $vendor = Vendor::factory()->for($user)->create([
                'company_name' => $company,
                'status' => $vendorStatus,
                'risk_score' => in_array($submissionStatus, [Submission::STATUS_APPROVED, Submission::STATUS_REJECTED], true) ? $risk : 0,
            ]);

            $decided = in_array($submissionStatus, [Submission::STATUS_APPROVED, Submission::STATUS_REJECTED], true);

            $submission = Submission::factory()->for($vendor)->create([
                'status' => $submissionStatus,
                'composite_risk_score' => $risk,
                'risk_level' => $level,
                'reviewed_by' => $decided ? $officer->id : null,
                'reviewed_at' => $decided ? now()->subDays(rand(1, 10)) : null,
                'review_comments' => $decided && $submissionStatus === Submission::STATUS_REJECTED
                    ? 'Forensic flags could not be cleared on manual review.'
                    : null,
                'created_at' => now()->subDays(rand(1, 14)),
            ]);

            foreach (collect($typeIds)->shuffle()->take(2) as $typeId) {
                $document = Document::factory()->for($submission)->for($vendor)->create([
                    'document_type_id' => $typeId,
                    'processing_status' => Document::STATUS_COMPLETED,
                    'created_at' => $submission->created_at,
                ]);

                ValidationResult::factory()->create([
                    'document_id' => $document->id,
                    'submission_id' => $submission->id,
                    'document_risk_score' => $risk,
                    'flags' => $flags,
                ]);
            }

            Notification::factory()->create([
                'user_id' => $officer->id,
                'type' => $level === 'high' ? Notification::TYPE_HIGH_RISK_ALERT : Notification::TYPE_GENERAL,
                'subject' => $level === 'high' ? 'High-risk submission' : 'Submission ready for review',
                'body' => $level === 'high'
                    ? sprintf('High-risk submission detected (score: %d/100) from %s.', (int) $risk, $company)
                    : sprintf('Submission from %s is ready for review.', $company),
                'related_submission_id' => $submission->id,
            ]);
        }
    }
}
