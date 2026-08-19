<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration signature enrollment captures THREE bond-paper signatures.
     * `signature_embedding` keeps holding the single runtime reference the
     * pipeline verifies against — now the L2-normalised centroid of the three —
     * so the §5 Stage 4a verify path is unchanged. These two columns preserve the
     * per-signature audit trail: the three 128-D vectors (+ their detection boxes)
     * and the mean pairwise cosine consistency the enrollment gate accepted on.
     */
    public function up(): void
    {
        Schema::table('vendor_embeddings', function (Blueprint $table) {
            $table->json('signature_samples')->nullable()->after('signature_embedding');
            $table->float('signature_consistency')->nullable()->after('signature_samples');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_embeddings', function (Blueprint $table) {
            $table->dropColumn(['signature_samples', 'signature_consistency']);
        });
    }
};
