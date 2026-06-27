<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `issuer_scope` records who issues a document type, which drives how its
     * logo is verified in Stage 4b:
     *  - 'national' → one logo agency-wide (BIR, SEC); matched by document_type alone.
     *  - 'lgu'      → one seal per city; matched by (document_type, detected_city).
     *  - NULL       → no official issuer logo (e.g. audited financial statement,
     *                 signed contract); the logo-reference lookup is skipped.
     *
     * Scope lives on the document type (it is intrinsic to the type), so an admin
     * can add a new type and pick its scope with no code or schema change.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->enum('issuer_scope', ['lgu', 'national'])->nullable()->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('issuer_scope');
        });
    }
};
