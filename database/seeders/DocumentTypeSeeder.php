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

        $types = [
            ['name' => 'BIR Permit', 'code' => 'bir_permit', 'description' => 'Bureau of Internal Revenue business permit', 'is_required' => true],
            ['name' => 'General Information Sheet', 'code' => 'gis', 'description' => 'SEC General Information Sheet', 'is_required' => true],
            ['name' => 'Financial Statement', 'code' => 'financial_stmt', 'description' => 'Audited financial statement', 'is_required' => true],
            ['name' => 'Business Permit', 'code' => 'business_permit', 'description' => 'Local government business permit', 'is_required' => false],
            ['name' => 'Signed Contract', 'code' => 'signed_contract', 'description' => 'Signed accreditation contract/agreement', 'is_required' => false],
        ];

        DB::table('document_types')->insertOrIgnore(array_map(fn (array $type) => [
            ...$type,
            'created_at' => $now,
            'updated_at' => $now,
        ], $types));
    }
}
