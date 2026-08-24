<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The per-vendor stamp reference is superseded by the per-city
     * `city_logo_references` table (logos belong to the issuing city, not the
     * vendor — see ADVS_System_Reference.md §4b / §8). `vendor_embeddings` now
     * holds only the per-vendor signature reference (enrolled at registration).
     */
    public function up(): void
    {
        Schema::table('vendor_embeddings', function (Blueprint $table) {
            $table->dropColumn(['stamp_embedding', 'stamp_image_path', 'stamp_enrolled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_embeddings', function (Blueprint $table) {
            $table->json('stamp_embedding')->nullable()->after('signature_enrolled_at');
            $table->string('stamp_image_path', 500)->nullable()->after('stamp_embedding');
            $table->timestamp('stamp_enrolled_at')->nullable()->after('stamp_image_path');
        });
    }
};
