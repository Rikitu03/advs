<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference logo/stamp/seal library (EfficientNet feature vectors), keyed by
     * the issuer of the document — NOT by vendor.
     *
     * The issuer is derived from `document_types.issuer_scope`:
     *  - national doc type → `city` is NULL; one reference per `document_type_id`.
     *  - lgu doc type      → `city` is set; one reference per (`document_type_id`, `city`).
     *
     * A row is seeded when a compliance officer approves the first document
     * carrying that issuer's logo (see ADVS_System_Reference.md §4b / §8).
     * Supersedes the per-vendor `vendor_embeddings.stamp_*` columns.
     */
    public function up(): void
    {
        Schema::create('logo_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            // Empty-string sentinel '' for national issuers (BIR/SEC); the canonical
            // (normalized) city name for LGU issuers, e.g. "Pasig". NOT NULL (default
            // '') is deliberate: MySQL treats NULL as distinct in a unique index, so a
            // nullable city would let two national rows share a document type. With ''
            // the unique(document_type_id, city) below enforces one logo per national
            // document type. The OCR-detected city is normalized the same way before lookup.
            $table->string('city', 100)->default('');

            $table->string('label', 150)->nullable();          // e.g. "BIR" or "Pasig Business Permit Seal"
            $table->json('feature_vector');                    // EfficientNet vector — cast to array on the model
            $table->string('reference_image_path', 500);       // stored crop of the genuine logo

            // Provenance: the approved document + officer that seeded this reference.
            $table->foreignId('seeded_from_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();

            $table->timestamps();

            // One reference per issuer: (document_type, '') for national,
            // (document_type, city) for LGU — both enforced because city is never NULL.
            $table->unique(['document_type_id', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logo_references');
    }
};
