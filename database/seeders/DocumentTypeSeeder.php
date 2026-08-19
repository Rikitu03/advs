<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Seed the supported document types for the Negofood food-business
     * (vendor) accreditation domain.
     *
     * The `code` of each type matches the ResNet-50 classifier's class folder name
     * (python/data/.../classifier_data/<code>/) so Stage 3's predicted label maps
     * straight onto a document type. The canonical taxonomy, per-type expiry, and
     * balanced dataset targets are documented in
     * python/data/CLASSIFIER_DATASET_SPEC.md.
     *
     * `issuer_scope` drives Stage 4b logo verification: 'national' = one agency logo
     * matched by document type (BIR/SEC/DTI/FDA + ID-issuing agencies); 'lgu' = one
     * seal per city matched by document type + detected city; null = no official
     * issuer logo (the logo-reference lookup is skipped).
     *
     * `is_required` is a COARSE, deployment-wide default — the real per-vendor
     * rules (which docs a Food Supplier vs a Beverage Distributor must submit,
     * and "any one government ID") will live in the planned `requirement_profiles`
     * tables. The classifier-only negative classes (`fake`, `other`) are NOT
     * document types and are intentionally excluded here.
     *
     * NOTE: `requires_expiry` is part of the taxonomy (see the spec) but the
     * `document_types` table has no such column yet — it needs a migration
     * (pipeline Phase P1) before it can be seeded. Until then expiry intent lives
     * in CLASSIFIER_DATASET_SPEC.md only.
     */
    public function run(): void
    {
        $now = now();

        $types = [
            // --- Business registration & permits ---
            ['name' => 'BIR Certificate of Registration', 'code' => 'bir_certificate', 'description' => 'BIR Certificate of Registration (Form 2303)', 'is_required' => true, 'issuer_scope' => 'national'],
            ['name' => 'SEC Certificate of Registration', 'code' => 'sec_registration', 'description' => 'SEC registration for corporations/partnerships', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'SEC General Information Sheet', 'code' => 'sec_gis', 'description' => 'SEC General Information Sheet (annual filing)', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'DTI Business Name Registration', 'code' => 'dti_registration', 'description' => 'DTI Business Name registration for sole proprietors', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'Business Permit', 'code' => 'business_permit', 'description' => "LGU Mayor's / Business Permit", 'is_required' => true, 'issuer_scope' => 'lgu'],

            // --- Food-safety / operations ---
            ['name' => 'Sanitary Permit', 'code' => 'sanitary_permit', 'description' => 'LGU City Health sanitary permit', 'is_required' => true, 'issuer_scope' => 'lgu'],
            ['name' => 'FDA Registration', 'code' => 'fda_registration', 'description' => 'FDA License to Operate / Certificate of Product Registration', 'is_required' => true, 'issuer_scope' => 'national'],
            ['name' => 'Food Handler Certificate', 'code' => 'food_handler_certificate', 'description' => "Food Handler's Certificate / Health Card", 'is_required' => true, 'issuer_scope' => 'lgu'],

            // --- Contractual ---
            // NOTE: Financial Statement was removed from the vendor-submittable
            // taxonomy (not one of the three supported types: BIR / Business
            // Permit / DTI). Existing documents were re-pointed to DTI by the
            // 2026_07_22 repoint migration.
            ['name' => 'Signed Contract', 'code' => 'signed_contract', 'description' => 'Signed accreditation contract/agreement', 'is_required' => false, 'issuer_scope' => null],

            // --- Government IDs of the authorized representative (one type per ID; single national issuing agency each) ---
            ['name' => 'PhilSys National ID', 'code' => 'national_id', 'description' => 'PhilSys National ID (PhilID)', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'PhilHealth ID', 'code' => 'philhealth_id', 'description' => 'PhilHealth member ID', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'SSS ID', 'code' => 'sss_id', 'description' => 'Social Security System ID', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'UMID', 'code' => 'umid', 'description' => 'Unified Multi-Purpose ID', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'Postal ID', 'code' => 'postal_id', 'description' => 'PHLPost Postal ID', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => "Driver's License", 'code' => 'drivers_license', 'description' => "LTO Driver's License", 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'Passport', 'code' => 'passport', 'description' => 'DFA Philippine Passport', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'PRC ID', 'code' => 'prc_id', 'description' => 'PRC professional identification card', 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => "Voter's ID", 'code' => 'voters_id', 'description' => "COMELEC Voter's ID", 'is_required' => false, 'issuer_scope' => 'national'],
            ['name' => 'TIN ID', 'code' => 'tin_id', 'description' => 'BIR Taxpayer Identification Number ID', 'is_required' => false, 'issuer_scope' => 'national'],
        ];

        // updateOrInsert (keyed on the unique `code`) so re-seeding is idempotent and
        // backfills new columns onto rows created before they existed. Run
        // `php artisan migrate:fresh --seed` for a clean taxonomy (renamed codes from
        // the pre-Negofood seed are not auto-removed on an in-place re-seed).
        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'is_required' => $type['is_required'],
                    'issuer_scope' => $type['issuer_scope'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
