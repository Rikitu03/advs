<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Seed the supported document types referenced in the thesis
     * (mirrors ADVS_Final_Schema.sql §3 seed block).
     */
    public function run(): void
    {
        $now = now();

        // `issuer_scope` drives Stage 4b logo verification: 'national' = one agency
        // logo (BIR/SEC, matched by document type); 'lgu' = one seal per city
        // (matched by document type + detected city); null = no official issuer logo.
        $types = [
            ['name' => 'BIR Permit', 'code' => 'bir_permit', 'description' => 'Bureau of Internal Revenue business permit', 'is_required' => true, 'issuer_scope' => 'national'],
            ['name' => 'General Information Sheet', 'code' => 'gis', 'description' => 'SEC General Information Sheet', 'is_required' => true, 'issuer_scope' => 'national'],
            ['name' => 'Financial Statement', 'code' => 'financial_stmt', 'description' => 'Audited financial statement', 'is_required' => true, 'issuer_scope' => null],
            ['name' => 'Business Permit', 'code' => 'business_permit', 'description' => 'Local government business permit', 'is_required' => false, 'issuer_scope' => 'lgu'],
            ['name' => 'Signed Contract', 'code' => 'signed_contract', 'description' => 'Signed accreditation contract/agreement', 'is_required' => false, 'issuer_scope' => null],
        ];

        // updateOrInsert (keyed on the unique `code`) so re-seeding backfills
        // issuer_scope onto rows created before the column existed.
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
