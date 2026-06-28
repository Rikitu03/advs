<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage T forensic-tampering output, one row per processed document
     * (one-to-one with `documents`, alongside `validation_results`). Kept in its
     * own table so the per-stage ML row stays focused; every column is nullable
     * because each technique can be skipped (fail-forward) on a given document.
     */
    public function up(): void
    {
        Schema::create('tamper_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();

            // Aggregate verdict (see python/forensics: 0 clean .. 1 tampered).
            $table->double('tamper_score')->nullable();
            $table->double('tamper_authenticity')->nullable();
            $table->double('tamper_confidence')->nullable();
            $table->boolean('hard_flag')->default(false);
            $table->boolean('tamper_passed')->nullable();

            // Per-technique breakdown (score / threshold / pass / flags / detail).
            $table->json('metadata_result')->nullable();        // T1
            $table->json('ela_result')->nullable();             // T2
            $table->json('copy_move_result')->nullable();       // T3
            $table->json('font_result')->nullable();            // T4
            $table->json('cross_reference_result')->nullable(); // T5

            $table->json('flags')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tamper_analyses');
    }
};
