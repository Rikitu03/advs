<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Static sample data for the Compliance Officer dashboard frontend.
 *
 * This is presentational placeholder data only — it lets the Home dashboard,
 * the Pending Submissions queue, and the Validation Results drill-down render
 * realistic, internally-consistent content (the queue rows link to matching
 * drill-down detail) without a database or the ML pipeline.
 *
 * Replace these methods with Eloquent queries (Submission / ValidationResult /
 * Vendor / Notification models) when the document pipeline lands in Phase 8.
 * Thresholds and risk bands mirror ADVS_System_Reference.md §6 / §9.
 */
class DemoData
{
    public const TEXT_THRESHOLD = 70;

    public const CLASSIFICATION_THRESHOLD = 70;

    public const SIGNATURE_THRESHOLD = 75;

    public const STAMP_THRESHOLD = 85;

    /**
     * Every demo submission, keyed by reference number for easy drill-down lookup.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function submissions(): Collection
    {
        return collect([
            [
                'id' => 1042,
                'ref' => 'SUB-1042',
                'vendor' => 'Maria Santos',
                'company' => 'Santos Trading Corp.',
                'document_type' => 'BIR Permit',
                'submitted_at' => Carbon::now()->subHours(18),
                'status' => 'pending_review',
                'risk_score' => 78,
                'flags' => ['Signature mismatch', 'Stamp not detected'],
                'risk_driver' => 'Signature mismatch is the primary risk driver.',
                'documents' => [
                    ['name' => 'bir_certificate_of_registration.pdf', 'type' => 'BIR Permit', 'pages' => 2, 'size' => '1.8 MB', 'status' => 'completed'],
                    ['name' => 'business_permit_2026.jpg', 'type' => 'Business Registration', 'pages' => 1, 'size' => '920 KB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 88, 'pass' => true, 'matched' => 13, 'expected' => 14, 'detail' => '13 of 14 expected fields matched. Missing: "Line of Business".'],
                    'classification' => ['label' => 'BIR Permit', 'confidence' => 96, 'pass' => true, 'detail' => 'Classified as “BIR Permit” with high confidence.'],
                    'signature' => ['detected' => true, 'similarity' => 43, 'distance' => 1.87, 'distance_threshold' => 1.20, 'pass' => false, 'detail' => 'Embedding distance 1.87 exceeds the 1.20 threshold.'],
                    'stamp' => ['detected' => false, 'similarity' => null, 'cosine' => null, 'pass' => false, 'detail' => 'No stamp region detected by YOLOv8 (missing-component penalty applied).'],
                ],
                'ocr_excerpt' => "REPUBLIC OF THE PHILIPPINES\nDEPARTMENT OF FINANCE\nBUREAU OF INTERNAL REVENUE\n\nCERTIFICATE OF REGISTRATION\nTIN: 274-118-902-000   RDO: 044\nRegistered Name: SANTOS TRADING CORP.\nDate of Registration: 14 January 2019",
            ],
            [
                'id' => 1039,
                'ref' => 'SUB-1039',
                'vendor' => 'Daniel Cruz',
                'company' => 'Cruz Logistics Inc.',
                'document_type' => 'Financial Statement',
                'submitted_at' => Carbon::now()->subHours(26),
                'status' => 'pending_review',
                'risk_score' => 71,
                'flags' => ['Low OCR confidence', 'Classification below threshold'],
                'risk_driver' => 'Poor scan quality drove both OCR and classification down.',
                'documents' => [
                    ['name' => 'audited_fs_2025.pdf', 'type' => 'Financial Statement', 'pages' => 2, 'size' => '3.1 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 54, 'pass' => false, 'matched' => 7, 'expected' => 16, 'detail' => 'Only 7 of 16 expected fields matched — likely a low-resolution scan.'],
                    'classification' => ['label' => 'Financial Statement', 'confidence' => 63, 'pass' => false, 'detail' => 'Confidence 63% is below the 70% threshold.'],
                    'signature' => ['detected' => true, 'similarity' => 81, 'distance' => 0.94, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.94 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 88, 'cosine' => 0.88, 'pass' => true, 'detail' => 'Cosine similarity 0.88 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "CRUZ LOGISTICS INC.\nSTATEMENT OF FINANCIAL POSITION\nAs of December 31, 2025\n\nTotal Assets ............ 24,810,455\nTotal Liabilities ....... 9,442,100\n[low-confidence region]",
            ],
            [
                'id' => 1044,
                'ref' => 'SUB-1044',
                'vendor' => 'Grace Lim',
                'company' => 'Lim Hardware & Supply',
                'document_type' => 'Business Registration',
                'submitted_at' => Carbon::now()->subHours(6),
                'status' => 'pending_review',
                'risk_score' => 64,
                'flags' => ['Stamp mismatch'],
                'risk_driver' => 'Stamp similarity fell just below threshold.',
                'documents' => [
                    ['name' => 'dti_registration.png', 'type' => 'Business Registration', 'pages' => 1, 'size' => '1.2 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 91, 'pass' => true, 'matched' => 10, 'expected' => 11, 'detail' => '10 of 11 expected fields matched.'],
                    'classification' => ['label' => 'Business Registration', 'confidence' => 93, 'pass' => true, 'detail' => 'Classified as “Business Registration”.'],
                    'signature' => ['detected' => true, 'similarity' => 79, 'distance' => 1.02, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 1.02 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 72, 'cosine' => 0.72, 'pass' => false, 'detail' => 'Cosine similarity 0.72 below the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "DEPARTMENT OF TRADE AND INDUSTRY\nCERTIFICATE OF BUSINESS NAME REGISTRATION\n\nBusiness Name: LIM HARDWARE & SUPPLY\nCertificate No.: 2024-0099182\nValid Until: 21 March 2029",
            ],
            [
                'id' => 1041,
                'ref' => 'SUB-1041',
                'vendor' => 'Antonio Reyes',
                'company' => 'Reyes Construction',
                'document_type' => 'BIR Permit',
                'submitted_at' => Carbon::now()->subHours(40),
                'status' => 'pending_review',
                'risk_score' => 47,
                'flags' => ['1 field missing'],
                'risk_driver' => 'Minor text validation gap; all forensic checks passed.',
                'documents' => [
                    ['name' => 'bir_permit.pdf', 'type' => 'BIR Permit', 'pages' => 1, 'size' => '1.0 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 76, 'pass' => true, 'matched' => 12, 'expected' => 14, 'detail' => '12 of 14 expected fields matched.'],
                    'classification' => ['label' => 'BIR Permit', 'confidence' => 89, 'pass' => true, 'detail' => 'Classified as “BIR Permit”.'],
                    'signature' => ['detected' => true, 'similarity' => 84, 'distance' => 0.81, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.81 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 90, 'cosine' => 0.90, 'pass' => true, 'detail' => 'Cosine similarity 0.90 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "BUREAU OF INTERNAL REVENUE\nCERTIFICATE OF REGISTRATION\nRegistered Name: REYES CONSTRUCTION\nTIN: 419-552-660-000",
            ],
            [
                'id' => 1045,
                'ref' => 'SUB-1045',
                'vendor' => 'Bea Villanueva',
                'company' => 'Villanueva Foods',
                'document_type' => 'Financial Statement',
                'submitted_at' => Carbon::now()->subHours(3),
                'status' => 'pending_review',
                'risk_score' => 22,
                'flags' => [],
                'risk_driver' => 'All components passed comfortably.',
                'documents' => [
                    ['name' => 'financial_statement_2025.pdf', 'type' => 'Financial Statement', 'pages' => 2, 'size' => '2.4 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 95, 'pass' => true, 'matched' => 16, 'expected' => 16, 'detail' => 'All 16 expected fields matched.'],
                    'classification' => ['label' => 'Financial Statement', 'confidence' => 98, 'pass' => true, 'detail' => 'Classified as “Financial Statement”.'],
                    'signature' => ['detected' => true, 'similarity' => 92, 'distance' => 0.58, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.58 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 95, 'cosine' => 0.95, 'pass' => true, 'detail' => 'Cosine similarity 0.95 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "VILLANUEVA FOODS\nSTATEMENT OF COMPREHENSIVE INCOME\nFor the year ended December 31, 2025\n\nNet Revenue ............ 41,209,880\nNet Income ............. 6,118,540",
            ],
            [
                'id' => 1046,
                'ref' => 'SUB-1046',
                'vendor' => 'Carlos Mendoza',
                'company' => 'Mendoza Pharma',
                'document_type' => 'BIR Permit',
                'submitted_at' => Carbon::now()->subHours(1),
                'status' => 'pending_review',
                'risk_score' => 15,
                'flags' => [],
                'risk_driver' => 'Clean submission — no flags raised.',
                'documents' => [
                    ['name' => 'bir_cor.pdf', 'type' => 'BIR Permit', 'pages' => 1, 'size' => '880 KB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 97, 'pass' => true, 'matched' => 14, 'expected' => 14, 'detail' => 'All 14 expected fields matched.'],
                    'classification' => ['label' => 'BIR Permit', 'confidence' => 99, 'pass' => true, 'detail' => 'Classified as “BIR Permit”.'],
                    'signature' => ['detected' => true, 'similarity' => 96, 'distance' => 0.41, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.41 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 97, 'cosine' => 0.97, 'pass' => true, 'detail' => 'Cosine similarity 0.97 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "BUREAU OF INTERNAL REVENUE\nCERTIFICATE OF REGISTRATION\nRegistered Name: MENDOZA PHARMA\nTIN: 502-771-114-000",
            ],

            // ── Decided submissions (Archived Reports) ───────────────────────
            [
                'id' => 1037,
                'ref' => 'SUB-1037',
                'vendor' => 'Joel Garcia',
                'company' => 'Garcia Textiles',
                'document_type' => 'Business Registration',
                'submitted_at' => Carbon::now()->subDays(4),
                'status' => 'approved',
                'decision' => 'approved',
                'reviewed_by' => 'Compliance Officer',
                'reviewed_at' => Carbon::now()->subDays(4)->addHours(3),
                'review_comments' => 'All components verified against the enrolled references.',
                'risk_score' => 19,
                'flags' => [],
                'risk_driver' => 'Clean submission — accredited.',
                'documents' => [
                    ['name' => 'dti_registration.pdf', 'type' => 'Business Registration', 'pages' => 1, 'size' => '1.1 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 96, 'pass' => true, 'matched' => 11, 'expected' => 11, 'detail' => 'All 11 expected fields matched.'],
                    'classification' => ['label' => 'Business Registration', 'confidence' => 97, 'pass' => true, 'detail' => 'Classified as “Business Registration”.'],
                    'signature' => ['detected' => true, 'similarity' => 94, 'distance' => 0.52, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.52 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 93, 'cosine' => 0.93, 'pass' => true, 'detail' => 'Cosine similarity 0.93 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "DEPARTMENT OF TRADE AND INDUSTRY\nCERTIFICATE OF BUSINESS NAME REGISTRATION\nBusiness Name: GARCIA TEXTILES",
            ],
            [
                'id' => 1033,
                'ref' => 'SUB-1033',
                'vendor' => 'Ramon Aquino',
                'company' => 'Aquino Motors',
                'document_type' => 'BIR Permit',
                'submitted_at' => Carbon::now()->subDays(7),
                'status' => 'approved',
                'decision' => 'approved',
                'reviewed_by' => 'Compliance Officer 2',
                'reviewed_at' => Carbon::now()->subDays(6)->addHours(20),
                'review_comments' => 'Minor text gap accepted; forensic checks strong.',
                'risk_score' => 28,
                'flags' => ['1 field missing'],
                'risk_driver' => 'Minor OCR gap; signature and stamp verified.',
                'documents' => [
                    ['name' => 'bir_cor.pdf', 'type' => 'BIR Permit', 'pages' => 1, 'size' => '0.9 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 81, 'pass' => true, 'matched' => 13, 'expected' => 14, 'detail' => '13 of 14 expected fields matched.'],
                    'classification' => ['label' => 'BIR Permit', 'confidence' => 92, 'pass' => true, 'detail' => 'Classified as “BIR Permit”.'],
                    'signature' => ['detected' => true, 'similarity' => 88, 'distance' => 0.71, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.71 within the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 90, 'cosine' => 0.90, 'pass' => true, 'detail' => 'Cosine similarity 0.90 above the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "BUREAU OF INTERNAL REVENUE\nCERTIFICATE OF REGISTRATION\nRegistered Name: AQUINO MOTORS",
            ],
            [
                'id' => 1029,
                'ref' => 'SUB-1029',
                'vendor' => 'Stephanie Tan',
                'company' => 'Tan Imports',
                'document_type' => 'BIR Permit',
                'submitted_at' => Carbon::now()->subDays(9),
                'status' => 'rejected',
                'decision' => 'rejected',
                'reviewed_by' => 'Compliance Officer',
                'reviewed_at' => Carbon::now()->subDays(8)->addHours(5),
                'review_comments' => 'Signature and stamp both failed verification — likely forged.',
                'risk_score' => 84,
                'flags' => ['Signature mismatch', 'Stamp mismatch'],
                'risk_driver' => 'Both forensic checks failed.',
                'documents' => [
                    ['name' => 'bir_permit_scan.pdf', 'type' => 'BIR Permit', 'pages' => 2, 'size' => '2.0 MB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 74, 'pass' => true, 'matched' => 11, 'expected' => 14, 'detail' => '11 of 14 expected fields matched.'],
                    'classification' => ['label' => 'BIR Permit', 'confidence' => 85, 'pass' => true, 'detail' => 'Classified as “BIR Permit”.'],
                    'signature' => ['detected' => true, 'similarity' => 38, 'distance' => 1.94, 'distance_threshold' => 1.20, 'pass' => false, 'detail' => 'Embedding distance 1.94 exceeds the 1.20 threshold.'],
                    'stamp' => ['detected' => true, 'similarity' => 61, 'cosine' => 0.61, 'pass' => false, 'detail' => 'Cosine similarity 0.61 below the 0.85 threshold.'],
                ],
                'ocr_excerpt' => "BUREAU OF INTERNAL REVENUE\nCERTIFICATE OF REGISTRATION\nRegistered Name: TAN IMPORTS",
            ],
            [
                'id' => 1025,
                'ref' => 'SUB-1025',
                'vendor' => 'Patricia Flores',
                'company' => 'Flores Apparel',
                'document_type' => 'Financial Statement',
                'submitted_at' => Carbon::now()->subDays(12),
                'status' => 'rejected',
                'decision' => 'rejected',
                'reviewed_by' => 'Compliance Officer 2',
                'reviewed_at' => Carbon::now()->subDays(11)->addHours(2),
                'review_comments' => 'Document type could not be confirmed; resubmission requested.',
                'risk_score' => 73,
                'flags' => ['Classification below threshold', 'Low OCR confidence'],
                'risk_driver' => 'Unrecognised document type.',
                'documents' => [
                    ['name' => 'statement.png', 'type' => 'Unknown', 'pages' => 1, 'size' => '640 KB', 'status' => 'completed'],
                ],
                'components' => [
                    'text' => ['score' => 49, 'pass' => false, 'matched' => 5, 'expected' => 16, 'detail' => 'Only 5 of 16 expected fields matched.'],
                    'classification' => ['label' => 'Unknown', 'confidence' => 41, 'pass' => false, 'detail' => 'Confidence 41% is well below the 70% threshold.'],
                    'signature' => ['detected' => true, 'similarity' => 80, 'distance' => 0.97, 'distance_threshold' => 1.20, 'pass' => true, 'detail' => 'Embedding distance 0.97 within the 1.20 threshold.'],
                    'stamp' => ['detected' => false, 'similarity' => null, 'cosine' => null, 'pass' => false, 'detail' => 'No stamp region detected by YOLOv8.'],
                ],
                'ocr_excerpt' => "[low-confidence region]\nunstructured text\n...",
            ],
        ])->map(function (array $s): array {
            $s['risk_level'] = self::riskLevel($s['risk_score']);
            $s['decision'] ??= null;
            $s['reviewed_by'] ??= null;
            $s['reviewed_at'] ??= null;
            $s['review_comments'] ??= null;

            return $s;
        });
    }

    /**
     * Find one submission by numeric id or reference number.
     *
     * @return array<string, mixed>|null
     */
    public static function findSubmission(int|string $id): ?array
    {
        return self::submissions()->first(
            fn (array $s): bool => (string) $s['id'] === (string) $id || $s['ref'] === $id
        );
    }

    /**
     * The five highest-risk pending submissions, for the Home quick-access list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function urgentSubmissions(int $limit = 5): Collection
    {
        return self::pendingSubmissions()->take($limit);
    }

    /**
     * Pending-review queue, sorted by risk score (highest first) per §5 Stage 6.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function pendingSubmissions(): Collection
    {
        return self::submissions()
            ->where('status', 'pending_review')
            ->sortByDesc('risk_score')
            ->values();
    }

    /**
     * Decided submissions for the Archived Reports tab (§4), newest decision first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function archivedReports(): Collection
    {
        return self::submissions()
            ->whereIn('status', ['approved', 'rejected'])
            ->sortByDesc('reviewed_at')
            ->values();
    }

    /**
     * Registered vendor directory for the Vendor Profiles tab (§4 / §8).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function vendors(): Collection
    {
        return collect([
            ['id' => 1, 'company' => 'Santos Trading Corp.', 'contact' => 'Maria Santos', 'registration_number' => '274-118-902-000', 'registered_at' => Carbon::parse('2019-01-14'), 'phone' => '+63 917 555 0142', 'address' => '12 Ortigas Ave, Pasig City', 'status' => 'under_review', 'risk_score' => 78, 'enrolled' => true],
            ['id' => 2, 'company' => 'Cruz Logistics Inc.', 'contact' => 'Daniel Cruz', 'registration_number' => '318-552-110-000', 'registered_at' => Carbon::parse('2020-06-03'), 'phone' => '+63 917 555 0199', 'address' => '88 Quezon Ave, Quezon City', 'status' => 'under_review', 'risk_score' => 71, 'enrolled' => true],
            ['id' => 3, 'company' => 'Garcia Textiles', 'contact' => 'Joel Garcia', 'registration_number' => '402-771-300-000', 'registered_at' => Carbon::parse('2018-09-21'), 'phone' => '+63 917 555 0233', 'address' => '5 Shaw Blvd, Mandaluyong', 'status' => 'approved', 'risk_score' => 19, 'enrolled' => true],
            ['id' => 4, 'company' => 'Aquino Motors', 'contact' => 'Ramon Aquino', 'registration_number' => '511-220-880-000', 'registered_at' => Carbon::parse('2021-02-11'), 'phone' => '+63 917 555 0277', 'address' => '210 EDSA, Cubao', 'status' => 'approved', 'risk_score' => 28, 'enrolled' => true],
            ['id' => 5, 'company' => 'Tan Imports', 'contact' => 'Stephanie Tan', 'registration_number' => '622-119-540-000', 'registered_at' => Carbon::parse('2022-11-30'), 'phone' => '+63 917 555 0301', 'address' => '47 Roxas Blvd, Manila', 'status' => 'rejected', 'risk_score' => 84, 'enrolled' => false],
            ['id' => 6, 'company' => 'Villanueva Foods', 'contact' => 'Bea Villanueva', 'registration_number' => '733-441-900-000', 'registered_at' => Carbon::parse('2023-04-18'), 'phone' => '+63 917 555 0356', 'address' => '9 Katipunan Ave, Quezon City', 'status' => 'pending', 'risk_score' => 22, 'enrolled' => false],
            ['id' => 7, 'company' => 'Mendoza Pharma', 'contact' => 'Carlos Mendoza', 'registration_number' => '844-552-118-000', 'registered_at' => Carbon::parse('2024-08-07'), 'phone' => '+63 917 555 0410', 'address' => '3 Buendia Ave, Makati', 'status' => 'pending', 'risk_score' => 15, 'enrolled' => false],
        ])->map(function (array $v): array {
            $history = self::submissions()->where('company', $v['company'])->sortByDesc('submitted_at')->values();
            $v['submissions'] = $history;
            $v['submissions_count'] = $history->count();
            $v['last_submission_at'] = $history->first()['submitted_at'] ?? null;

            // Reference biometrics are enrolled on first approval (§5 4a/4b).
            $v['signature_ref'] = $v['enrolled']
                ? ['model' => 'Siamese CNN', 'dimensions' => 128, 'enrolled_at' => $v['registered_at']->copy()->addDays(3)]
                : null;
            $v['stamp_ref'] = $v['enrolled']
                ? ['model' => 'EfficientNet', 'metric' => 'cosine', 'enrolled_at' => $v['registered_at']->copy()->addDays(3)]
                : null;

            return $v;
        });
    }

    /**
     * Find one vendor by id.
     *
     * @return array<string, mixed>|null
     */
    public static function findVendor(int|string $id): ?array
    {
        return self::vendors()->first(fn (array $v): bool => (string) $v['id'] === (string) $id);
    }

    /**
     * Home KPI cards (ADVS_System_Reference.md §4 Compliance Officer dashboard).
     *
     * @return array<string, int|string>
     */
    public static function kpis(): array
    {
        $pending = self::pendingSubmissions();

        return [
            'pending' => $pending->count(),
            'flagged_today' => $pending
                ->where('submitted_at', '>=', Carbon::now()->startOfDay())
                ->filter(fn (array $s): bool => count($s['flags']) > 0)
                ->count(),
            'high_risk' => $pending->where('risk_level', 'high')->count(),
            'approval_rate' => '88%',
        ];
    }

    /**
     * Officer notification / activity feed (ADVS_System_Reference.md §7).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function activity(): Collection
    {
        return collect([
            ['type' => 'high_risk_alert', 'icon' => 'exclamation-triangle', 'color' => 'rose', 'text' => 'High-risk submission detected (score 78/100) from Santos Trading Corp.', 'at' => Carbon::now()->subHours(18)],
            ['type' => 'document_flagged', 'icon' => 'flag', 'color' => 'amber', 'text' => 'Submission from Cruz Logistics Inc. flagged: low OCR confidence.', 'at' => Carbon::now()->subHours(26)],
            ['type' => 'submission_received', 'icon' => 'inbox-arrow-down', 'color' => 'sky', 'text' => 'New submission SUB-1046 from Mendoza Pharma requires review.', 'at' => Carbon::now()->subHours(1)],
            ['type' => 'decision_made', 'icon' => 'check-circle', 'color' => 'emerald', 'text' => 'You approved SUB-1037 (Garcia Textiles).', 'at' => Carbon::now()->subHours(5)],
            ['type' => 'overdue', 'icon' => 'clock', 'color' => 'amber', 'text' => 'Submission SUB-1041 has been pending review for 40+ hours.', 'at' => Carbon::now()->subHours(2)],
        ]);
    }

    /**
     * Flag-type metadata for the Risk Logs tab (§4): label, icon, and tint
     * for each of the four filterable flag categories.
     *
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function flagTypes(): array
    {
        return [
            'text_mismatch' => ['label' => 'Text mismatch', 'icon' => 'document-magnifying-glass', 'color' => 'sky'],
            'low_classification' => ['label' => 'Low classification confidence', 'icon' => 'cpu-chip', 'color' => 'amber'],
            'signature_mismatch' => ['label' => 'Signature mismatch', 'icon' => 'pencil-square', 'color' => 'rose'],
            'stamp_mismatch' => ['label' => 'Stamp mismatch', 'icon' => 'shield-exclamation', 'color' => 'rose'],
        ];
    }

    /**
     * Chronological audit log of every flag the pipeline has raised (§4 Risk
     * Logs). Entries are derived from the demo submissions' component results
     * so the log always agrees with each submission's drill-down view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function riskLogs(): Collection
    {
        $logs = collect();

        foreach (self::submissions() as $s) {
            // Flags land a few minutes after submission, once the pipeline finishes.
            $raisedAt = $s['submitted_at']->copy()->addMinutes(4);
            $c = $s['components'];

            if (! $c['text']['pass']) {
                $logs->push(self::flagEntry($s, $raisedAt, 'text_mismatch',
                    $c['text']['score'] < 55 ? 'high' : 'medium',
                    $c['text']['score'] < 55 ? 'Insufficient text extracted' : 'Required fields missing',
                    $c['text']['detail']));
            }

            if (! $c['classification']['pass']) {
                $logs->push(self::flagEntry($s, $raisedAt, 'low_classification',
                    $c['classification']['confidence'] < 50 ? 'high' : 'medium',
                    $c['classification']['label'] === 'Unknown' ? 'Unknown document type' : 'Classification below threshold',
                    $c['classification']['detail']));
            }

            if ($c['signature']['detected'] && ! $c['signature']['pass']) {
                $logs->push(self::flagEntry($s, $raisedAt, 'signature_mismatch', 'high',
                    'Signature mismatch — possible forgery', $c['signature']['detail']));
            }

            if (! $c['stamp']['detected']) {
                $logs->push(self::flagEntry($s, $raisedAt, 'stamp_mismatch', 'high',
                    'No stamp detected', $c['stamp']['detail']));
            } elseif (! $c['stamp']['pass']) {
                $logs->push(self::flagEntry($s, $raisedAt, 'stamp_mismatch',
                    $c['stamp']['similarity'] < 70 ? 'high' : 'medium',
                    'Stamp mismatch — suspect reproduction', $c['stamp']['detail']));
            }
        }

        return $logs
            ->sortByDesc('raised_at')
            ->values()
            ->map(fn (array $log, int $i): array => [...$log, 'id' => $i + 1]);
    }

    /**
     * Build one Risk Logs entry from a submission's component result.
     *
     * @param  array<string, mixed>  $submission
     * @return array<string, mixed>
     */
    private static function flagEntry(array $submission, Carbon $raisedAt, string $type, string $severity, string $title, string $detail): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'raised_at' => $raisedAt,
            'submission_id' => $submission['id'],
            'ref' => $submission['ref'],
            'vendor' => $submission['vendor'],
            'company' => $submission['company'],
            'document_type' => $submission['document_type'],
        ];
    }

    /**
     * Officer in-app notifications for the Notifications tab, newest first.
     * Events and wording follow the §7 event → recipient table.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function notifications(): Collection
    {
        return collect([
            ['id' => 1, 'type' => 'submission_received', 'icon' => 'inbox-arrow-down', 'color' => 'sky', 'title' => 'New submission requires review', 'body' => 'SUB-1046 from Mendoza Pharma has been processed and is awaiting review.', 'at' => Carbon::now()->subHour(), 'read' => false, 'submission_id' => 1046],
            ['id' => 2, 'type' => 'overdue', 'icon' => 'clock', 'color' => 'amber', 'title' => 'Submission pending 40+ hours', 'body' => 'SUB-1041 from Reyes Construction has been pending review for more than 40 hours.', 'at' => Carbon::now()->subHours(2), 'read' => false, 'submission_id' => 1041],
            ['id' => 3, 'type' => 'submission_received', 'icon' => 'inbox-arrow-down', 'color' => 'sky', 'title' => 'New submission requires review', 'body' => 'SUB-1045 from Villanueva Foods has been processed and is awaiting review.', 'at' => Carbon::now()->subHours(3), 'read' => false, 'submission_id' => 1045],
            ['id' => 4, 'type' => 'decision_made', 'icon' => 'check-circle', 'color' => 'emerald', 'title' => 'Decision recorded', 'body' => 'You approved SUB-1037 (Garcia Textiles).', 'at' => Carbon::now()->subHours(5), 'read' => true, 'submission_id' => 1037],
            ['id' => 5, 'type' => 'document_flagged', 'icon' => 'flag', 'color' => 'amber', 'title' => 'Submission flagged', 'body' => 'SUB-1044 from Lim Hardware & Supply flagged: stamp similarity below threshold.', 'at' => Carbon::now()->subHours(6), 'read' => true, 'submission_id' => 1044],
            ['id' => 6, 'type' => 'high_risk_alert', 'icon' => 'exclamation-triangle', 'color' => 'rose', 'title' => 'High-risk submission detected', 'body' => 'SUB-1042 from Santos Trading Corp. scored 78/100 — signature mismatch and missing stamp.', 'at' => Carbon::now()->subHours(18), 'read' => false, 'submission_id' => 1042],
            ['id' => 7, 'type' => 'document_flagged', 'icon' => 'flag', 'color' => 'amber', 'title' => 'Submission flagged', 'body' => 'SUB-1039 from Cruz Logistics Inc. flagged: low OCR confidence and classification below threshold.', 'at' => Carbon::now()->subHours(26), 'read' => true, 'submission_id' => 1039],
            ['id' => 8, 'type' => 'config_changed', 'icon' => 'cog-6-tooth', 'color' => 'zinc', 'title' => 'System threshold updated', 'body' => 'STAMP_SIMILARITY_THRESHOLD changed from 0.80 to 0.85 by System Administrator.', 'at' => Carbon::now()->subDays(2), 'read' => true, 'submission_id' => null],
            ['id' => 9, 'type' => 'decision_made', 'icon' => 'x-circle', 'color' => 'rose', 'title' => 'Decision recorded', 'body' => 'Compliance Officer 2 rejected SUB-1029 (Tan Imports) — possible forgery.', 'at' => Carbon::now()->subDays(8), 'read' => true, 'submission_id' => 1029],
        ])->sortByDesc('at')->values();
    }

    /**
     * Map a 0–100 composite risk score to a band (§5 Stage 5 / §9).
     */
    public static function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 61 => 'high',
            $score >= 31 => 'medium',
            default => 'low',
        };
    }

    /**
     * Flux badge color for a risk band (green / yellow / red per §4 + §6).
     */
    public static function riskColor(string $level): string
    {
        return match ($level) {
            'high' => 'red',
            'medium' => 'amber',
            default => 'emerald',
        };
    }

    /**
     * Flux badge color for a vendor accreditation status.
     */
    public static function vendorStatusColor(string $status): string
    {
        return match ($status) {
            'approved' => 'emerald',
            'rejected' => 'red',
            'under_review' => 'amber',
            default => 'zinc',
        };
    }
}
