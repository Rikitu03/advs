<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue of machine-learning model files used by the ADVS validation
     * pipeline (ResNet-50, YOLOv8, Siamese CNN, EfficientNet — see
     * ADVS_System_Reference.md §5 and CLAUDE.md §6).
     *
     * Each row is a managed *registration* of a weight file. The actual
     * weight bytes live under `python/models/` on disk; the row mirrors
     * their existence, version, status, and last-modified timestamp so the
     * admin "ML Model Management" panel can render availability + change
     * history without touching the Python layer.
     *
     * Storage layout (defaults, both override-able in config):
     *   - `python/models/*.h5`  → Keras (ResNet-50, Siamese)
     *   - `python/models/*.pt`  → Ultralytics (YOLOv8, EfficientNet wrapper)
     */
    public function up(): void
    {
        Schema::create('ml_models', function (Blueprint $table) {
            $table->id();

            // Human-readable identifier (e.g. "Document Classifier",
            // "Signature Verifier"). Unique because each model slot has a
            // single active registration at a time.
            $table->string('name', 100)->unique();

            // Pipeline stage this model plugs into — see ADVS §5. Mirrors
            // the categories listed in `ADVS_System_Reference.md` §9.
            // Values: classification | detection | signature | stamp_logo.
            $table->string('purpose', 50);

            // SemVer-ish version string the panel renders in the header
            // column. Defaults to "1.0.0" when a row is first registered.
            $table->string('version', 20)->default('1.0.0');

            // Deployment state. Drives the status badge + row actions.
            // Values: active | standby | deprecated | missing.
            //   active     → pipeline is using this file today
            //   standby    → registered but not currently wired in (e.g. A/B)
            //   deprecated → superseded by a newer version; kept for audit
            //   missing    → expected file was not found at `storage_path`
            $table->string('status', 20)->default('standby');

            // Relative path (under `python/models/`) or absolute filesystem
            // path the scanner checks at sync time. We store the original
            // string verbatim so the operator can relocate a model without
            // dropping its history.
            $table->string('storage_path', 500);

            // Last-known file size in bytes. Refreshed by
            // {@see \App\Services\MlModelScanner} on every "Sync" so the
            // panel can warn if a file was truncated or swapped out.
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // SHA-256 of the file contents. Optional — left NULL when the
            // scanner is run with `--no-hash` or when the file is missing.
            // Stored as a 64-char hex string.
            $table->string('checksum_sha256', 64)->nullable();

            // Free-form notes / provenance — trained on dataset X, sourced
            // from team drive Y, last evaluated on Z, etc.
            $table->text('notes')->nullable();

            // Latest-known accuracy / metric snapshot. Stored as JSON
            // because each model has different metrics (top-1 accuracy,
            // mAP@0.5, EER, cosine-similarity, …).
            $table->json('metrics')->nullable();

            // Last time the underlying file changed on disk. Set/refreshed
            // by the scanner; null until the first successful scan.
            $table->timestamp('last_trained_at')->nullable();

            // Last time the admin panel re-checked the file's existence.
            // NULL until the first sync. Distinct from `last_trained_at`
            // because a sync may run without a fresh training.
            $table->timestamp('last_synced_at')->nullable();

            // Admin who last changed the row's *configuration* (status,
            // notes, version). nullable + nullOnDelete so deleting an admin
            // doesn't take their registered models with them.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('purpose');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_models');
    }
};
