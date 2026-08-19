<?php

namespace Tests\Feature\Document;

use App\Models\Document;
use App\Models\Submission;
use App\Models\Vendor;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepointFinancialStatementMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runRepointMigration(): void
    {
        // `require` (not _once) re-executes the file, returning the migration instance.
        $migration = require database_path('migrations/2026_07_22_170000_repoint_financial_statement_documents_to_dti.php');
        $migration->up();
    }

    public function test_up_repoints_financial_statement_documents_to_dti_and_removes_the_type(): void
    {
        $this->seed(DocumentTypeSeeder::class);
        $dtiId = (int) DB::table('document_types')->where('code', 'dti_registration')->value('id');

        // Simulate a legacy database that still carries the removed type + a doc on it.
        $financialStatementId = DB::table('document_types')->insertGetId([
            'name' => 'Financial Statement', 'code' => 'financial_statement',
            'description' => 'Audited financial statement', 'is_required' => true,
            'issuer_scope' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vendor = Vendor::factory()->create();
        $submission = Submission::factory()->for($vendor)->create();
        $document = Document::factory()->for($vendor)->for($submission)
            ->create(['document_type_id' => $financialStatementId]);

        $this->runRepointMigration();

        $this->assertDatabaseMissing('document_types', ['code' => 'financial_statement']);
        $this->assertSame($dtiId, (int) $document->fresh()->document_type_id);
    }

    public function test_up_is_idempotent_when_the_type_is_already_gone(): void
    {
        $this->seed(DocumentTypeSeeder::class); // no financial_statement type

        $this->runRepointMigration(); // must not throw

        $this->assertDatabaseMissing('document_types', ['code' => 'financial_statement']);
    }
}
