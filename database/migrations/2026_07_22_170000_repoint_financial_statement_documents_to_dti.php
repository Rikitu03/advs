<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Financial Statement is no longer part of the vendor-submittable taxonomy —
     * the three supported types are BIR, Business Permit, and DTI (matching the
     * vendor submission workflow and the per-type OCR templates). This re-points
     * any existing `financial_statement` documents to `dti_registration` and
     * removes the now-unused `financial_statement` document type.
     *
     * Data-preserving: submissions and their documents survive; only the type
     * pointer changes. Idempotent — a no-op once the type is gone (so it is safe
     * on a fresh migrate where the seeder never creates the type).
     */
    public function up(): void
    {
        $financialStatementId = DB::table('document_types')->where('code', 'financial_statement')->value('id');

        if ($financialStatementId === null) {
            return;
        }

        $dtiId = DB::table('document_types')->where('code', 'dti_registration')->value('id');

        if ($dtiId !== null) {
            DB::table('documents')
                ->where('document_type_id', $financialStatementId)
                ->update(['document_type_id' => $dtiId]);
        }

        DB::table('document_types')->where('id', $financialStatementId)->delete();
    }

    /**
     * The removal is not reversibly restorable — the financial_statement type is
     * re-created (so a rollback leaves a valid taxonomy) but the specific
     * documents re-pointed to DTI are not moved back, since they are now
     * indistinguishable from genuine DTI documents.
     */
    public function down(): void
    {
        if (DB::table('document_types')->where('code', 'financial_statement')->doesntExist()) {
            DB::table('document_types')->insert([
                'name' => 'Financial Statement',
                'code' => 'financial_statement',
                'description' => 'Audited financial statement',
                'is_required' => true,
                'issuer_scope' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
