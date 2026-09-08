<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sample data for the vendor-facing prototype.
 *
 * These records are intentionally presentational only. They give the vendor
 * portal believable dashboard, upload, submission history, and notification
 * states until the real Submission models and pipeline jobs are connected.
 */
class VendorDemoData
{
    /**
     * A submission is one accreditation batch that bundles one or more
     * documents (mirrors the real Submission → hasMany(Document) model), so
     * each entry carries a `documents` array rather than a single file.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function submissions(): Collection
    {
        return collect([
            [
                'id' => 2004,
                'ref' => 'SUB-2004',
                'submitted_at' => Carbon::now()->subHours(3),
                'status' => 'Processing',
                'status_tone' => 'processing',
                'note' => 'Your documents are being prepared for validation.',
                'documents' => [
                    ['type' => 'Business Permit', 'file_name' => 'business_permit_2026.pdf', 'size' => '2.4 MB'],
                    ['type' => 'BIR Registration', 'file_name' => 'bir_certificate_registration.pdf', 'size' => '980 KB'],
                    ['type' => 'DTI Registration', 'file_name' => 'dti_business_name_registration_2026.png', 'size' => '1.4 MB'],
                ],
            ],
            [
                'id' => 2003,
                'ref' => 'SUB-2003',
                'submitted_at' => Carbon::now()->subDays(1)->subHours(4),
                'status' => 'Pending Review',
                'status_tone' => 'review',
                'note' => 'Validation is complete and waiting for officer review.',
                'documents' => [
                    ['type' => 'Business Permit', 'file_name' => 'pasig_business_permit_scan.png', 'size' => '1.1 MB'],
                ],
            ],
            [
                'id' => 2002,
                'ref' => 'SUB-2002',
                'submitted_at' => Carbon::now()->subDays(9),
                'status' => 'Approved',
                'status_tone' => 'approved',
                'note' => 'This submission was accepted and added to your vendor record.',
                'documents' => [
                    ['type' => 'BIR Registration', 'file_name' => 'bir_certificate_registration.pdf', 'size' => '980 KB'],
                ],
            ],
            [
                'id' => 2001,
                'ref' => 'SUB-2001',
                'submitted_at' => Carbon::now()->subDays(18),
                'status' => 'Resubmission Requested',
                'status_tone' => 'resubmission_requested',
                'note' => 'A clearer scan is required — please resubmit before this submission can be accepted.',
                'documents' => [
                    ['type' => 'DTI Registration', 'file_name' => 'dti_business_name_registration_2025.png', 'size' => '1.4 MB'],
                    ['type' => 'Business Permit', 'file_name' => 'business_permit_2025.pdf', 'size' => '2.1 MB'],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, int>
     */
    public static function kpis(): array
    {
        $submissions = self::submissions();

        return [
            'total' => $submissions->count(),
            'processing' => $submissions->whereIn('status', ['Processing', 'Pending Review'])->count(),
            'approved' => $submissions->where('status', 'Approved')->count(),
            'resubmission' => $submissions->where('status', 'Resubmission Requested')->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function notifications(): Collection
    {
        return collect([
            [
                'id' => 1,
                'icon' => 'inbox-arrow-down',
                'color' => 'sky',
                'title' => 'Your submission has been received',
                'body' => 'SUB-2004 has been received for OCR, classification, signature checks, and stamp verification.',
                'at' => Carbon::now()->subHours(3),
                'read' => false,
            ],
            [
                'id' => 2,
                'icon' => 'clock',
                'color' => 'amber',
                'title' => 'Processing complete',
                'body' => 'SUB-2003 has been processed and is awaiting compliance officer review.',
                'at' => Carbon::now()->subDay()->subHours(2),
                'read' => false,
            ],
            [
                'id' => 3,
                'icon' => 'check-circle',
                'color' => 'emerald',
                'title' => 'Accreditation document approved',
                'body' => 'Your BIR Registration submission was approved and added to your vendor record.',
                'at' => Carbon::now()->subDays(9),
                'read' => true,
            ],
            [
                'id' => 4,
                'icon' => 'x-circle',
                'color' => 'rose',
                'title' => 'Resubmission requested',
                'body' => 'Your financial statement scan requires resubmission because several required fields were unreadable.',
                'at' => Carbon::now()->subDays(18),
                'read' => true,
            ],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function activity(): Collection
    {
        return collect([
            ['icon' => 'document-check', 'color' => 'sky', 'text' => 'Business Permit uploaded as SUB-2004.', 'at' => Carbon::now()->subHours(3)],
            ['icon' => 'arrow-path', 'color' => 'amber', 'text' => 'PDF-to-PNG conversion finished for SUB-2004.', 'at' => Carbon::now()->subHours(2)->subMinutes(42)],
            ['icon' => 'check-circle', 'color' => 'emerald', 'text' => 'BIR Registration approved by Compliance Officer.', 'at' => Carbon::now()->subDays(9)],
        ]);
    }

    /**
     * @return list<array{label: string, value: string, complete: bool}>
     */
    public static function profileChecklist(): array
    {
        return [
            ['label' => 'Vendor account verified', 'value' => 'Complete', 'complete' => true],
            ['label' => 'Reference signature enrolled', 'value' => 'Complete', 'complete' => true],
            ['label' => 'Reference stamp enrolled', 'value' => 'Pending approval', 'complete' => false],
            ['label' => 'Business Permit for 2026', 'value' => 'In progress', 'complete' => false],
        ];
    }
}
