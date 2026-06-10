<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference signature (128-D Siamese) and stamp (EfficientNet) feature
     * vectors per vendor, set during enrollment. Mirrors ADVS_Final_Schema.sql §7.
     */
    public function up(): void
    {
        Schema::create('vendor_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('signature_embedding')->nullable();
            $table->string('signature_image_path', 500)->nullable();
            $table->timestamp('signature_enrolled_at')->nullable();
            $table->json('stamp_embedding')->nullable();
            $table->string('stamp_image_path', 500)->nullable();
            $table->timestamp('stamp_enrolled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_embeddings');
    }
};
