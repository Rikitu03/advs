<?php

namespace Tests\Feature\Database;

use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the canonical document-type taxonomy: codes must match the ResNet-50
 * classifier folder names (so Stage 3's label maps onto a type), the classifier
 * negative classes must not leak in as document types, and issuer_scope must be
 * set correctly for Stage 4b. See python/data/CLASSIFIER_DATASET_SPEC.md.
 */
class DocumentTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedTypes(): Collection
    {
        $this->seed(DocumentTypeSeeder::class);

        return DB::table('document_types')->pluck('issuer_scope', 'code');
    }

    public function test_it_seeds_the_full_taxonomy_without_negative_classes(): void
    {
        $types = $this->seedTypes();

        // 24 real, submittable document types (fake/other are classifier-only).
        $this->assertSame(24, $types->count());
        $this->assertArrayNotHasKey('fake', $types->all());
        $this->assertArrayNotHasKey('other', $types->all());
    }

    public function test_codes_match_the_classifier_folder_names(): void
    {
        $types = $this->seedTypes();

        // A representative slice spanning every group.
        foreach ([
            'bir_certificate', 'sec_registration', 'sec_gis', 'dti_registration', 'business_permit',
            'sanitary_permit', 'fda_registration', 'food_handler_certificate',
            'financial_statement', 'signed_contract', 'employment_contract',
            'nbi_clearance', 'police_clearance', 'health_medical_certificate',
            'national_id', 'philhealth_id', 'sss_id', 'umid', 'postal_id',
            'drivers_license', 'passport', 'prc_id', 'voters_id', 'tin_id',
        ] as $code) {
            $this->assertArrayHasKey($code, $types->all(), "missing document type: {$code}");
        }

        // Pre-Negofood codes must be gone (renamed to match the folders).
        foreach (['bir_permit', 'financial_stmt', 'gis'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $types->all(), "legacy code still seeded: {$legacy}");
        }
    }

    public function test_issuer_scope_is_set_per_type(): void
    {
        $types = $this->seedTypes();

        $this->assertSame('national', $types['bir_certificate']);
        $this->assertSame('lgu', $types['business_permit']);
        $this->assertSame('lgu', $types['sanitary_permit']);
        $this->assertNull($types['financial_statement']);
        $this->assertNull($types['employment_contract']);
        $this->assertSame('national', $types['national_id']); // gov IDs verify the issuing-agency logo
    }

    public function test_every_code_fits_the_column_and_is_unique(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $codes = DB::table('document_types')->pluck('code')->all();

        $this->assertSame(count($codes), count(array_unique($codes)), 'duplicate document_type code');
        foreach ($codes as $code) {
            $this->assertLessThanOrEqual(30, strlen($code), "code exceeds column limit (30): {$code}");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->assertSame(24, DB::table('document_types')->count());
    }
}
