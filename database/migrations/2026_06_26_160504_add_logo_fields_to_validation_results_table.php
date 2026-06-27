<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the issuer-keyed logo verification on each document:
     *  - `detected_city`      — the issuing city OCR extracted (Stage 2). Used to scope
     *                           the lookup only for LGU document types; recorded regardless.
     *  - `stamp_tampered`     — EfficientNet tamper result; runs even when no reference exists.
     *  - `logo_reference_id`  — which `logo_references` row this logo was matched against.
     *
     * "Unreferenced logo" and "city not identified" outcomes stay in the existing
     * `flags` JSON; the `stamp_*` score columns are reused (they read as
     * stamp/logo/seal). See ADVS_System_Reference.md §4b.
     */
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->string('detected_city', 100)->nullable()->after('ocr_confidence');
            $table->boolean('stamp_tampered')->nullable()->after('stamp_passed');
            $table->foreignId('logo_reference_id')->nullable()->after('stamp_tampered')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_reference_id');
            $table->dropColumn(['detected_city', 'stamp_tampered']);
        });
    }
};
