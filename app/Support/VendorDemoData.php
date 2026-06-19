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
     * @return Collection<int, array<string, mixed>>
     */
    public static function submissions(): Collection
    {
        return collect([
            [
                'id' => 2004,
                'ref' => 'SUB-2004',
                'document_type' => 'Business Permit',
                'file_name' => 'business_permit_2026.pdf',
                'submitted_at' => Carbon::now()->subHours(3),
                'status' => 'Processing',
                'status_tone' => 'processing',
                'size' => '2.4 MB',
                'pages' => 2,
                'progress' => 25,
                'note' => 'Your document is being prepared for validation.',
            ],
            [
                'id' => 2003,
                'ref' => 'SUB-2003',
                'document_type' => 'Business Permit',
                'file_name' => 'pasig_business_permit_scan.png',
                'submitted_at' => Carbon::now()->subDays(1)->subHours(4),
                'status' => 'Pending Review',
                'status_tone' => 'review',
                'size' => '1.1 MB',
                'pages' => 1,
                'progress' => 75,
                'note' => 'Validation is complete and waiting for officer review.',
            ],
            [
                'id' => 2002,
                'ref' => 'SUB-2002',
                'document_type' => 'BIR Permit',
                'file_name' => 'bir_certificate_registration.pdf',
                'submitted_at' => Carbon::now()->subDays(9),
                'status' => 'Approved',
                'status_tone' => 'approved',
                'size' => '980 KB',
                'pages' => 1,
                'progress' => 100,
                'note' => 'This document was accepted and added to your vendor record.',
            ],
            [
                'id' => 2001,
                'ref' => 'SUB-2001',
                'document_type' => 'Financial Statement',
                'file_name' => 'audited_financial_statement_2025.pdf',
                'submitted_at' => Carbon::now()->subDays(18),
                'status' => 'Rejected',
                'status_tone' => 'rejected',
                'size' => '3.8 MB',
                'pages' => 2,
                'progress' => 100,
                'note' => 'A clearer scan is required before this document can be accepted.',
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
            'rejected' => $submissions->where('status', 'Rejected')->count(),
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
                'body' => 'Your BIR Permit submission was approved and added to your vendor record.',
                'at' => Carbon::now()->subDays(9),
                'read' => true,
            ],
            [
                'id' => 4,
                'icon' => 'x-circle',
                'color' => 'rose',
                'title' => 'Resubmission requested',
                'body' => 'Your financial statement scan was rejected because several required fields were unreadable.',
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
            ['icon' => 'check-circle', 'color' => 'emerald', 'text' => 'BIR Permit approved by Compliance Officer.', 'at' => Carbon::now()->subDays(9)],
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
