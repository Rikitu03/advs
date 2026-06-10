<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ML pipeline output, one row per processed document. Every stage's result
     * is nullable (a stage can be skipped). Mirrors ADVS_Final_Schema.sql §6.
     */
    public function up(): void
    {
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();

            // Stage 2: OCR
            $table->longText('ocr_extracted_text')->nullable();
            $table->double('ocr_confidence')->nullable();
            $table->double('text_validation_score')->nullable();
            $table->unsignedSmallInteger('text_fields_matched')->default(0)->nullable();
            $table->unsignedSmallInteger('text_fields_expected')->default(0)->nullable();

            // Stage 3: ResNet-50 classification
            $table->string('classification_label', 100)->nullable();
            $table->double('classification_confidence')->nullable();

            // Stage 4: YOLOv8 detection
            $table->boolean('signature_detected')->default(false)->nullable();
            $table->json('signature_bbox')->nullable();
            $table->boolean('stamp_detected')->default(false)->nullable();
            $table->json('stamp_bbox')->nullable();

            // Stage 4a: Siamese CNN — signature
            $table->double('signature_score')->nullable();
            $table->double('signature_distance')->nullable();
            $table->boolean('signature_passed')->nullable();

            // Stage 4b: EfficientNet — stamp
            $table->double('stamp_score')->nullable();
            $table->double('stamp_similarity')->nullable();
            $table->boolean('stamp_passed')->nullable();

            // Aggregate
            $table->double('document_risk_score')->nullable();
            $table->json('flags')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
    }
};
