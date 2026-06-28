<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individual uploaded files within a submission, one row per file with its
     * own processing status. Mirrors ADVS_Final_Schema.sql §5.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('file_path', 500);
            $table->string('converted_image_path', 500)->nullable();
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedSmallInteger('page_number')->default(1)->nullable();
            $table->enum('processing_status', [
                'queued', 'preprocessing', 'ocr', 'classifying',
                'detecting', 'verifying', 'forensics', 'completed', 'failed',
            ])->default('queued')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
