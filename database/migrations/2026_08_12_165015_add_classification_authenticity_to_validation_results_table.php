<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 reports two different numbers and the pipeline needs both: the
 * winning class's `classification_confidence` (what the officer reads) and
 * `classification_authenticity` = 1 - P(fake) (what the risk blend consumes).
 * They diverge exactly when it matters — a document confidently classified
 * `fake` has high confidence and near-zero authenticity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->float('classification_authenticity')->nullable()->after('classification_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->dropColumn('classification_authenticity');
        });
    }
};
