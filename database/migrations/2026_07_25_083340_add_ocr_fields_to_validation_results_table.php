<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 2 already extracts structured key/value pairs per document type
     * (13 BIR / 6 business-permit / 7 DTI fields), but until now only the raw
     * `ocr_extracted_text` blob and four aggregate counters were persisted, so
     * the officer drill-down could only render prose instead of the fields.
     *
     * `ocr_fields` stores the API's per-page field map verbatim:
     * `{key: {name, value, required, matched, confidence, source?, warnings}}`
     * — see python/scripts/ocr_dryrun.py extract_fields()/annotate_field_warnings().
     *
     * Nullable with no backfill: rows written before this column existed keep a
     * null map, and the drill-down falls back to their raw text until the
     * document is reprocessed.
     */
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->json('ocr_fields')->nullable()->after('ocr_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->dropColumn('ocr_fields');
        });
    }
};
